<?php
declare(strict_types=1);
require_once __DIR__ . '/stnc_client.php';

final class explorer_status
{
    public static function load(array $configuration): array
    {
        $diagnostics = [];

        foreach (['primary', 'secondary'] as $name) {
            if (empty($configuration[$name . '_ip'])) {
                continue;
            }

            try {
                $client = new stnc_client(
                    $configuration[$name . '_ip'],
                    (int) $configuration[$name . '_port']
                );

                $response = $client->getChainInfo();

                if ($response['status'] !== 0) {
                    $status = (int) $response['status'];

                    $diagnostics[$name] = [
                        'result' => 'stnc_error',
                        'status' => $status,
                    ];

                    /*
                     * STN_RPC_UNAVAILABLE (5) is a valid response from a
                     * reachable STNC node. The Chain endpoint is online,
                     * but it cannot currently provide an INFO snapshot.
                     * Do not misreport that condition as an offline Chain.
                     */
                    if ($status === 5) {
                        return [
                            'state' => 'online',
                            'message' => 'Blockchain connected; current Chain data is unavailable.',
                            'source' => $name,
                            'info' => null,
                            'data_available' => false,
                            'stnc_status' => 5,
                            'diagnostics' => $diagnostics,
                        ];
                    }

                    continue;
                }

                return [
                    'state' => 'online',
                    'message' => 'Blockchain connected.',
                    'source' => $name,
                    'info' => self::decode($response['payload']),
                    'data_available' => true,
                    'stnc_status' => 0,
                    'diagnostics' => [
                        $name => [
                            'result' => 'ok',
                            'status' => 0,
                        ],
                    ],
                ];
            } catch (stnc_exception | InvalidArgumentException $e) {
                $diagnostics[$name] = [
                    'result' => 'exception',
                    'detail' => $e->getMessage(),
                ];
            }
        }

        $details = [];

        foreach ($diagnostics as $name => $diagnostic) {
            $result = (string) ($diagnostic['result'] ?? 'unknown');

            if ($result === 'stnc_error') {
                $details[] = $name
                    . ': STNC status '
                    . (string) ($diagnostic['status'] ?? 'unknown');
                continue;
            }

            if ($result === 'exception') {
                $details[] = $name
                    . ': '
                    . (string) ($diagnostic['detail'] ?? 'unknown exception');
                continue;
            }

            $details[] = $name . ': ' . $result;
        }

        return [
            'state' => 'unavailable',
            'message' => $details === []
                ? 'Blockchain status is temporarily unavailable.'
                : 'Blockchain status unavailable — ' . implode(' | ', $details),
            'diagnostics' => $diagnostics,
        ];
    }

    public static function decode(string $payload): array
    {
        if (strlen($payload) !== 184) {
            throw new InvalidArgumentException('Invalid INFO payload');
        }

        return [
            'Network ID' => bin2hex(substr($payload, 0, 32)),
            'Genesis ID' => bin2hex(substr($payload, 32, 32)),
            'Chain height' => self::decimal(substr($payload, 64, 8)),
            'Tip block ID' => bin2hex(substr($payload, 72, 32)),
            'Cumulative work (hex)' => bin2hex(substr($payload, 104, 40)),
            'Current target (hex)' => bin2hex(substr($payload, 144, 32)),
            'Stored block count' => self::decimal(substr($payload, 180, 4)),
            'Validation status' => substr($payload, 176, 4) === "\0\0\0\1"
                ? 'Validated under current local rules'
                : 'Unknown node status',
        ];
    }

    private static function decimal(string $bytes): string
    {
        $digits = '0';

        foreach (str_split($bytes) as $byte) {
            $carry = ord($byte);

            for ($i = strlen($digits) - 1; $i >= 0; --$i) {
                $value = ((int) $digits[$i]) * 256 + $carry;
                $digits[$i] = (string) ($value % 10);
                $carry = intdiv($value, 10);
            }

            while ($carry > 0) {
                $digits = (string) ($carry % 10) . $digits;
                $carry = intdiv($carry, 10);
            }
        }

        return $digits;
    }
}