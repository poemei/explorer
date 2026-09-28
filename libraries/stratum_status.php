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
    private const CONNECT_TIMEOUT = 1.0;
    private const READ_TIMEOUT_SECONDS = 2;
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

        $target = 'tcp://' . self::socketHost($host) . ':' . $port;
        $errno = 0;
        $error = '';

        $socket = @stream_socket_client(
            $target,
            $errno,
            $error,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            return self::unavailable(
                'CONNECTION_FAILED',
                $error !== '' ? $error : ('Unable to connect to ' . $target . '.')
            );
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, self::READ_TIMEOUT_SECONDS, 0);

        $request = "GET " . self::STATUS_PATH . " HTTP/1.1\r\n"
            . 'Host: ' . self::httpHost($host, $port) . "\r\n"
            . "Accept: application/json\r\n"
            . "Connection: close\r\n\r\n";

        if (!self::writeAll($socket, $request)) {
            fclose($socket);
            return self::unavailable('WRITE_FAILED', 'Unable to send the Stratum status request.');
        }

        $raw = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 4096);
            if ($chunk === false) {
                fclose($socket);
                return self::unavailable('READ_FAILED', 'Unable to read the Stratum status response.');
            }

            if ($chunk !== '') {
                $raw .= $chunk;
                if (strlen($raw) > self::MAX_HEADER_BYTES + self::MAX_RESPONSE_BYTES) {
                    fclose($socket);
                    return self::unavailable('RESPONSE_TOO_LARGE', 'Stratum status response exceeded the allowed size.');
                }
            }

            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                fclose($socket);
                return self::unavailable('TIMEOUT', 'Timed out waiting for the Stratum status response.');
            }
        }

        fclose($socket);

        $separator = strpos($raw, "\r\n\r\n");
        if ($separator === false || $separator > self::MAX_HEADER_BYTES) {
            return self::unavailable('INVALID_HTTP', 'Stratum returned an invalid HTTP response.');
        }

        $headerBlock = substr($raw, 0, $separator);
        $body = substr($raw, $separator + 4);
        $headerLines = explode("\r\n", $headerBlock);
        $statusLine = array_shift($headerLines);

        if (!is_string($statusLine)
            || preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $statusLine, $matches) !== 1) {
            return self::unavailable('INVALID_HTTP', 'Stratum returned an invalid HTTP status line.');
        }

        $httpStatus = (int) $matches[1];
        if ($httpStatus !== 200) {
            return self::unavailable('HTTP_ERROR', 'Stratum status endpoint returned HTTP ' . $httpStatus . '.');
        }

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return self::unavailable('RESPONSE_TOO_LARGE', 'Stratum status response exceeded the allowed size.');
        }

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
                'http_status' => 200,
            ],
        ];
    }

    /** @param resource $socket */
    private static function writeAll($socket, string $data): bool
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }

        return true;
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
