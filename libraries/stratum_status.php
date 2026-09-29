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
    private const TOTAL_TIMEOUT_SECONDS = 3.0;
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
        if (!filter_var('http://' . self::urlHost($host) . ':' . $port . self::STATUS_PATH, FILTER_VALIDATE_URL)) {
            return self::unavailable('INVALID_HOST', 'Stratum host is invalid.');
        }

        $url = 'http://' . self::urlHost($host) . ':' . $port . self::STATUS_PATH;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'protocol_version' => 1.1,
                'timeout' => self::TOTAL_TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => [
                    'Accept: application/json',
                    'User-Agent: STN-Explorer/1.1',
                    'Connection: close',
                ],
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return self::unavailable('CONNECTION_FAILED', 'Unable to open Stratum status endpoint.');
        }

        try {
            $meta = stream_get_meta_data($stream);
            $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
            $status = self::httpStatus($headers);

            if ($status === null) {
                return self::unavailable('INVALID_HTTP', 'Stratum status endpoint did not return a valid HTTP status.');
            }
            if ($status !== 200) {
                return self::unavailable('HTTP_ERROR', 'Stratum status endpoint returned HTTP ' . $status . '.');
            }

            $body = stream_get_contents($stream, self::MAX_RESPONSE_BYTES + 1);
            if ($body === false) {
                $meta = stream_get_meta_data($stream);
                if (($meta['timed_out'] ?? false) === true) {
                    return self::unavailable('TIMEOUT', 'Stratum status response timed out.');
                }
                return self::unavailable('READ_FAILED', 'Unable to read Stratum status response.');
            }

            $meta = stream_get_meta_data($stream);
            if (($meta['timed_out'] ?? false) === true) {
                return self::unavailable('TIMEOUT', 'Stratum status response timed out.');
            }
        } finally {
            fclose($stream);
        }

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return self::unavailable('RESPONSE_TOO_LARGE', 'Stratum status response exceeded the allowed size.');
        }
        if ($body === '') {
            return self::unavailable('EMPTY_RESPONSE', 'Stratum returned an empty response.');
        }

        $contentType = self::headerValue($headers, 'content-type');
        if ($contentType !== null && !str_starts_with(strtolower($contentType), 'application/json')) {
            return self::unavailable('INVALID_HTTP', 'Stratum status response was not JSON.');
        }

        $contentLength = self::headerValue($headers, 'content-length');
        if ($contentLength !== null) {
            if (preg_match('/^[0-9]+$/', $contentLength) !== 1 || (int) $contentLength !== strlen($body)) {
                return self::unavailable('INCOMPLETE_RESPONSE', 'Stratum status response length did not match Content-Length.');
            }
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
                'http_status' => $status,
            ],
        ];
    }

    private static function httpStatus(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('/^HTTP\/\S+\s+([0-9]{3})(?:\s|$)/i', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    private static function headerValue(array $headers, string $wanted): ?string
    {
        $wanted = strtolower($wanted);
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            $colon = strpos($header, ':');
            if ($colon === false) {
                continue;
            }
            if (strtolower(trim(substr($header, 0, $colon))) === $wanted) {
                return trim(substr($header, $colon + 1));
            }
        }
        return null;
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

    private static function urlHost(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[' . $host . ']';
        }
        return $host;
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
