<?php
declare(strict_types=1);

/**
 * STN-Stratum status client for the Explorer module.
 *
 * Retrieves and validates the read-only STN-Stratum /status endpoint.
 *
 * Stratum remains authoritative for the operational mining information
 * returned by this library. This library does not infer mining state.
 */
final class stratum_status
{
    private const EXPECTED_SERVICE = 'STN-Stratum';
    private const STATUS_PATH = '/status';
    private const READ_TIMEOUT = 5;
    private const MAX_RESPONSE_BYTES = 16384;

    /**
     * Load current STN-Stratum status.
     *
     * @param string $host Stratum API hostname or IP address.
     * @param int    $port Stratum API port.
     *
     * @return array
     */
    public static function load(string $host, int $port): array
    {
        $host = trim($host);

        if ($host === '') {
            return self::unavailable(
                'INVALID_HOST',
                'Stratum host is not configured.'
            );
        }

        if ($port < 1 || $port > 65535) {
            return self::unavailable(
                'INVALID_PORT',
                'Stratum port is invalid.'
            );
        }

        /*
         * STN-Stratum's public status endpoint is transported over
         * plain HTTP. The configured port does not imply TLS.
         */
        $url = sprintf(
            'http://%s:%d%s',
            self::urlHost($host),
            $port,
            self::STATUS_PATH
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::READ_TIMEOUT,
                'ignore_errors' => true,
                'header' => [
                    'Accept: application/json',
                    'Connection: close',
                ],
            ],
        ]);

        set_error_handler(
            static function (
                int $severity,
                string $message
            ): bool {
                throw new RuntimeException($message);
            }
        );

        try {
            $response = file_get_contents(
                $url,
                false,
                $context,
                0,
                self::MAX_RESPONSE_BYTES + 1
            );
        } catch (Throwable $e) {
            return self::unavailable(
                'CONNECTION_FAILED',
                $e->getMessage()
            );
        } finally {
            restore_error_handler();
        }

        if ($response === false) {
            return self::unavailable(
                'CONNECTION_FAILED',
                'Stratum status request failed.'
            );
        }

        if (strlen($response) > self::MAX_RESPONSE_BYTES) {
            return self::unavailable(
                'RESPONSE_TOO_LARGE',
                'Stratum status response exceeded the allowed size.'
            );
        }

        $httpStatus = self::httpStatus(
            $http_response_header ?? []
        );

        if ($httpStatus !== 200) {
            return self::unavailable(
                'HTTP_ERROR',
                'Stratum status endpoint returned HTTP '
                    . $httpStatus . '.'
            );
        }

        try {
            $decoded = json_decode(
                $response,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            return self::unavailable(
                'INVALID_JSON',
                'Stratum returned invalid JSON.'
            );
        }

        if (!is_array($decoded)) {
            return self::unavailable(
                'INVALID_RESPONSE',
                'Stratum status response is not an object.'
            );
        }

        $validated = self::validate($decoded);

        if ($validated === null) {
            return self::unavailable(
                'INVALID_RESPONSE',
                'Stratum status response failed validation.'
            );
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

    /**
     * Validate and normalize the Stratum /status response.
     *
     * Hashrate is reported by STN-Stratum in hashes per second (H/s).
     *
     * @param array $data Decoded response.
     *
     * @return array|null
     */
    private static function validate(array $data): ?array
    {
        $required = [
            'service',
            'status',
            'chain_connected',
            'chain_host',
            'chain_port',
            'work_available',
            'miners',
            'hashrate',
            'job_id',
            'base_id',
            'target',
            'uptime_seconds',
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
            if (
                $data[$field] !== null
                && !is_string($data[$field])
            ) {
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

    /**
     * Extract the HTTP response status code.
     *
     * @param array $headers Response headers.
     *
     * @return int
     */
    private static function httpStatus(array $headers): int
    {
        if (
            !isset($headers[0])
            || !is_string($headers[0])
        ) {
            return 0;
        }

        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})\b/',
                $headers[0],
                $matches
            ) !== 1
        ) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * Format a hostname or address for use in a URL.
     *
     * @param string $host Hostname or address.
     *
     * @return string
     */
    private static function urlHost(string $host): string
    {
        if (
            filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6
            ) !== false
        ) {
            return '[' . $host . ']';
        }

        return $host;
    }

    /**
     * Build a normalized unavailable result.
     *
     * @param string $result Diagnostic result.
     * @param string $detail Diagnostic detail.
     *
     * @return array
     */
    private static function unavailable(
        string $result,
        string $detail
    ): array {
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