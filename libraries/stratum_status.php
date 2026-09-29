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
    private const CONNECT_TIMEOUT_SECONDS = 1.0;
    private const IO_TIMEOUT_SECONDS = 2;
    private const MAX_HEADER_BYTES = 8192;
    private const MAX_RESPONSE_BYTES = 16384;

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
        $errstr = '';
        $socket = @fsockopen(
            self::socketHost($host),
            $port,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS
        );

        if ($socket === false) {
            $detail = 'Unable to connect to Stratum status endpoint.';
            if ($errno !== 0 || $errstr !== '') {
                $detail .= ' (' . $errno . ($errstr !== '' ? ': ' . $errstr : '') . ')';
            }
            return self::unavailable('CONNECTION_FAILED', $detail);
        }

        try {
            if (!stream_set_timeout($socket, self::IO_TIMEOUT_SECONDS)) {
                return self::unavailable('TRANSPORT_FAILED', 'Unable to configure Stratum status timeout.');
            }

            $request = "GET " . self::STATUS_PATH . " HTTP/1.1\r\n"
                . 'Host: ' . self::httpHost($host, $port) . "\r\n"
                . "Accept: application/json\r\n"
                . "User-Agent: STN-Explorer/1.1\r\n"
                . "Connection: close\r\n\r\n";

            $write = self::writeAll($socket, $request);
            if ($write !== null) {
                return $write;
            }

            $statusLine = fgets($socket, 1024);
            if ($statusLine === false) {
                return self::readFailure($socket, 'Unable to read Stratum HTTP status line.');
            }
            if (preg_match('/^HTTP\/\S+\s+([0-9]{3})(?:\s|$)/i', trim($statusLine), $matches) !== 1) {
                return self::unavailable('INVALID_HTTP', 'Stratum returned an invalid HTTP status line.');
            }
            $httpStatus = (int) $matches[1];

            $headers = [];
            $headerBytes = strlen($statusLine);
            while (true) {
                $line = fgets($socket, 4096);
                if ($line === false) {
                    return self::readFailure($socket, 'Unable to read Stratum HTTP headers.');
                }
                $headerBytes += strlen($line);
                if ($headerBytes > self::MAX_HEADER_BYTES) {
                    return self::unavailable('HEADERS_TOO_LARGE', 'Stratum response headers exceeded the allowed size.');
                }
                if ($line === "\r\n" || $line === "\n") {
                    break;
                }
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $name = strtolower(trim(substr($line, 0, $colon)));
                    $value = trim(substr($line, $colon + 1));
                    if ($name !== '') {
                        $headers[$name] = $value;
                    }
                }
            }

            if ($httpStatus !== 200) {
                return self::unavailable('HTTP_ERROR', 'Stratum status endpoint returned HTTP ' . $httpStatus . '.');
            }

            $contentType = $headers['content-type'] ?? null;
            if (!is_string($contentType) || !str_starts_with(strtolower($contentType), 'application/json')) {
                return self::unavailable('INVALID_HTTP', 'Stratum status response was not JSON.');
            }

            $contentLengthRaw = $headers['content-length'] ?? null;
            if (!is_string($contentLengthRaw) || preg_match('/^[0-9]+$/', $contentLengthRaw) !== 1) {
                return self::unavailable('INVALID_HTTP', 'Stratum returned an invalid Content-Length.');
            }
            $contentLength = (int) $contentLengthRaw;
            if ($contentLength < 1 || $contentLength > self::MAX_RESPONSE_BYTES) {
                return self::unavailable('RESPONSE_TOO_LARGE', 'Stratum status response length is invalid.');
            }

            $body = self::readExact($socket, $contentLength);
            if (is_array($body)) {
                return $body;
            }
        } finally {
            fclose($socket);
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
                'http_status' => $httpStatus,
            ],
        ];
    }

    private static function writeAll($socket, string $request): ?array
    {
        $offset = 0;
        $length = strlen($request);

        while ($offset < $length) {
            $written = fwrite($socket, substr($request, $offset));
            if ($written === false || $written === 0) {
                $meta = stream_get_meta_data($socket);
                if (($meta['timed_out'] ?? false) === true) {
                    return self::unavailable('TIMEOUT', 'Stratum status request timed out while writing.');
                }
                return self::unavailable('WRITE_FAILED', 'Unable to send Stratum status request.');
            }
            $offset += $written;
        }

        return null;
    }

    private static function readExact($socket, int $length): string|array
    {
        $body = '';

        while (strlen($body) < $length) {
            $chunk = fread($socket, $length - strlen($body));
            if ($chunk === false) {
                return self::readFailure($socket, 'Unable to read Stratum status body.');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (($meta['timed_out'] ?? false) === true) {
                    return self::unavailable('TIMEOUT', 'Stratum status response timed out.');
                }
                if (feof($socket)) {
                    return self::unavailable('INCOMPLETE_RESPONSE', 'Stratum closed the connection before the complete response body was received.');
                }
                continue;
            }
            $body .= $chunk;
        }

        return $body;
    }

    private static function readFailure($socket, string $detail): array
    {
        $meta = stream_get_meta_data($socket);
        if (($meta['timed_out'] ?? false) === true) {
            return self::unavailable('TIMEOUT', 'Stratum status response timed out.');
        }
        if (feof($socket)) {
            return self::unavailable('INCOMPLETE_RESPONSE', $detail . ' Connection closed by Stratum.');
        }
        return self::unavailable('READ_FAILED', $detail);
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
