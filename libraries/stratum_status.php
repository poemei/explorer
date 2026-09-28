<?php
declare(strict_types=1);

/**
 * STN-Stratum status client for the Explorer module.
 *
 * Retrieves and validates the read-only STN-Stratum /status endpoint.
 * Stratum remains authoritative for the operational mining information
 * returned by this library. This library does not infer mining state.
 */
final class stratum_status
{
    private const EXPECTED_SERVICE = 'STN-Stratum';
    private const STATUS_PATH = '/status';
    private const CONNECT_TIMEOUT = 3.0;
    private const READ_TIMEOUT_SECONDS = 3;
    private const MAX_RESPONSE_BYTES = 16384;
    private const MAX_HEADER_BYTES = 8192;

    public static function load(string $host, int $port): array
    {
        $host = trim($host);

        if ($host === '') {
            return self::unavailable('INVALID_HOST', 'Stratum host is not configured.');
        }
        if ($port < 1 || $port > 65535) {
            return self::unavailable('INVALID_PORT', 'Stratum port is invalid.');
        }

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            'tcp://' . self::socketHost($host) . ':' . $port,
            $errno,
            $error,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            return self::unavailable(
                'CONNECTION_FAILED',
                $error !== '' ? $error : 'Unable to connect to Stratum status endpoint.'
            );
        }

        stream_set_timeout($socket, self::READ_TIMEOUT_SECONDS);

        $request = 'GET ' . self::STATUS_PATH . " HTTP/1.1\r\n"
            . 'Host: ' . self::httpHost($host, $port) . "\r\n"
            . "Accept: application/json\r\n"
            . "Connection: close\r\n\r\n";

        $written = @fwrite($socket, $request);
        if ($written === false || $written !== strlen($request)) {
            fclose($socket);
            return self::unavailable('WRITE_FAILED', 'Unable to send the complete Stratum status request.');
        }

        $statusLine = @fgets($socket);
        if ($statusLine === false) {
            $meta = stream_get_meta_data($socket);
            fclose($socket);
            return self::unavailable(
                !empty($meta['timed_out']) ? 'TIMEOUT' : 'READ_FAILED',
                !empty($meta['timed_out'])
                    ? 'Timed out waiting for the Stratum HTTP status line.'
                    : 'Unable to read the Stratum HTTP status line.'
            );
        }

        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', rtrim($statusLine, "\r\n"), $matches) !== 1) {
            fclose($socket);
            return self::unavailable('INVALID_HTTP', 'Stratum returned an invalid HTTP status line.');
        }

        $httpStatus = (int) $matches[1];
        $contentLength = null;
        $headerBytes = strlen($statusLine);

        while (true) {
            $line = @fgets($socket);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                fclose($socket);
                return self::unavailable(
                    !empty($meta['timed_out']) ? 'TIMEOUT' : 'READ_FAILED',
                    !empty($meta['timed_out'])
                        ? 'Timed out waiting for the Stratum HTTP headers.'
                        : 'Unable to read the Stratum HTTP headers.'
                );
            }

            $headerBytes += strlen($line);
            if ($headerBytes > self::MAX_HEADER_BYTES) {
                fclose($socket);
                return self::unavailable('INVALID_HTTP', 'Stratum response headers exceeded the allowed size.');
            }

            if ($line === "\r\n" || $line === "\n") {
                break;
            }

            if (preg_match('/^Content-Length:\s*([0-9]+)\s*$/i', rtrim($line, "\r\n"), $lengthMatch) === 1) {
                $contentLength = (int) $lengthMatch[1];
            }
        }

        if ($httpStatus !== 200) {
            fclose($socket);
            return self::unavailable('HTTP_ERROR', 'Stratum status endpoint returned HTTP ' . $httpStatus . '.');
        }

        if ($contentLength === null) {
            fclose($socket);
            return self::unavailable('INVALID_HTTP', 'Stratum response did not include Content-Length.');
        }
        if ($contentLength < 1 || $contentLength > self::MAX_RESPONSE_BYTES) {
            fclose($socket);
            return self::unavailable('INVALID_LENGTH', 'Stratum response Content-Length is invalid.');
        }

        $body = '';
        while (strlen($body) < $contentLength) {
            $remaining = $contentLength - strlen($body);
            $chunk = @fread($socket, $remaining);

            if ($chunk === false) {
                $meta = stream_get_meta_data($socket);
                fclose($socket);
                return self::unavailable(
                    !empty($meta['timed_out']) ? 'TIMEOUT' : 'READ_FAILED',
                    !empty($meta['timed_out'])
                        ? 'Timed out waiting for the Stratum response body.'
                        : 'Unable to read the Stratum response body.'
                );
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    fclose($socket);
                    return self::unavailable('TIMEOUT', 'Timed out waiting for the Stratum response body.');
                }
                if (feof($socket)) {
                    fclose($socket);
                    return self::unavailable('TRUNCATED_RESPONSE', 'Stratum closed before the complete response body was received.');
                }
                continue;
            }

            $body .= $chunk;
        }

        fclose($socket);

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return self::unavailable('INVALID_JSON', 'Stratum returned invalid JSON.');
        }

        if (!is_array($decoded)) {
            return self::unavailable('INVALID_RESPONSE', 'Stratum status response is not an object.');
        }

        $validated = self::validate($decoded);
        if ($validated === null) {
            return self::unavailable('INVALID_RESPONSE', 'Stratum status response failed validation.');
        }

        return [
            'state' => 'online',
            'message' => 'Stratum connected.',
            'info' => $validated,
            'diagnostics' => [
                'result' => 'ok',
                'http_status' => $httpStatus,
            ],
        ];
    }

    private static function validate(array $data): ?array
    {
        $required = [
            'service', 'status', 'chain_connected', 'chain_host', 'chain_port',
            'work_available', 'miners', 'hashrate', 'job_id', 'base_id',
            'target', 'uptime_seconds',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                return null;
            }
        }

        if (
            !is_string($data['service'])
            || $data['service'] !== self::EXPECTED_SERVICE
            || !is_string($data['status'])
            || $data['status'] === ''
            || !is_bool($data['chain_connected'])
            || !is_string($data['chain_host'])
            || !is_int($data['chain_port'])
            || $data['chain_port'] < 1
            || $data['chain_port'] > 65535
            || !is_bool($data['work_available'])
            || !is_int($data['miners'])
            || $data['miners'] < 0
            || !is_int($data['hashrate'])
            || $data['hashrate'] < 0
            || !is_int($data['uptime_seconds'])
            || $data['uptime_seconds'] < 0
        ) {
            return null;
        }

        foreach (['job_id', 'base_id', 'target'] as $field) {
            if ($data[$field] !== null && !is_string($data[$field])) {
                return null;
            }
        }

        return [
            'service' => $data['service'],
            'status' => $data['status'],
            'chain_connected' => $data['chain_connected'],
            'chain_host' => $data['chain_host'],
            'chain_port' => $data['chain_port'],
            'work_available' => $data['work_available'],
            'miners' => $data['miners'],
            'hashrate' => $data['hashrate'],
            'job_id' => $data['job_id'],
            'base_id' => $data['base_id'],
            'target' => $data['target'],
            'uptime_seconds' => $data['uptime_seconds'],
        ];
    }

    private static function socketHost(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[' . $host . ']';
        }
        return $host;
    }

    private static function httpHost(string $host, int $port): string
    {
        return self::socketHost($host) . ':' . $port;
    }

    private static function unavailable(string $result, string $detail): array
    {
        return [
            'state' => 'unavailable',
            'message' => 'Stratum status is temporarily unavailable.',
            'diagnostics' => [
                'result' => $result,
                'detail' => $detail,
            ],
        ];
    }
}
