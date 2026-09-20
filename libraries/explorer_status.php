<?php
declare(strict_types=1);
require_once __DIR__ . '/stnc_client.php';

final class explorer_status
{
    public static function load(array $configuration): array
    {
        foreach (['primary', 'secondary'] as $name) {
            if (empty($configuration[$name . '_ip'])) { continue; }
            try {
                $client = new stnc_client($configuration[$name . '_ip'], (int) $configuration[$name . '_port']);
                $response = $client->getChainInfo();
                if ($response['status'] !== 0) { continue; }
                return ['state' => 'online', 'message' => 'Blockchain connected.', 'source' => $name,
                    'info' => self::decode($response['payload'])];
            } catch (stnc_exception | InvalidArgumentException $e) {
                // A failed endpoint cannot supply accepted status. Try the configured fallback.
            }
        }
        return ['state' => 'unavailable', 'message' => 'Blockchain status is temporarily unavailable.'];
    }

    public static function decode(string $payload): array
    {
        if (strlen($payload) !== 184) { throw new InvalidArgumentException('Invalid INFO payload'); }
        return [
            'Network ID' => bin2hex(substr($payload, 0, 32)),
            'Genesis ID' => bin2hex(substr($payload, 32, 32)),
            'Chain height' => self::decimal(substr($payload, 64, 8)),
            'Tip block ID' => bin2hex(substr($payload, 72, 32)),
            'Cumulative work (hex)' => bin2hex(substr($payload, 104, 40)),
            'Current target (hex)' => bin2hex(substr($payload, 144, 32)),
            'Stored block count' => self::decimal(substr($payload, 180, 4)),
            'Validation status' => substr($payload, 176, 4) === "\0\0\0\1"
                ? 'Validated under current local rules' : 'Unknown node status',
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
