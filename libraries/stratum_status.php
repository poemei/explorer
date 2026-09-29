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
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TOTAL_TIMEOUT_SECONDS = 5;
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
        if (!function_exists('curl_init')) {
            return self::unavailable('CURL_UNAVAILABLE', 'PHP cURL support is not available.');
        }

        $url = 'http://' . self::urlHost($host) . ':' . $port . self::STATUS_PATH;
        $response = '';

        $curl = curl_init();
        if ($curl === false) {
            return self::unavailable('CURL_INIT_FAILED', 'Unable to initialize PHP cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Connection: close',
            ],
            CURLOPT_USERAGENT => 'STN-Explorer/1.1',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($curl);
        $curlErrno = curl_errno($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($ok === false) {
            if ($curlErrno === CURLE_WRITE_ERROR && strlen($response) >= self::MAX_RESPONSE_BYTES) {
                return self::unavailable('RESPONSE_TOO_LARGE', 'Stratum status response exceeded the allowed size.');
            }

            return self::unavailable(
                'CURL_ERROR',
                $curlError !== ''
                    ? 'cURL ' . $curlErrno . ': ' . $curlError
                    : 'The Stratum status request failed.'
            );
        }

        if ($httpStatus !== 200) {
            return self::unavailable(
                'HTTP_ERROR',
                $httpStatus > 0
                    ? 'Stratum status endpoint returned HTTP ' . $httpStatus . '.'
                    : 'Stratum status endpoint did not return an HTTP status.'
            );
        }

        if ($response === '') {
            return self::unavailable('EMPTY_RESPONSE', 'Stratum returned an empty response.');
        }

        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
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
