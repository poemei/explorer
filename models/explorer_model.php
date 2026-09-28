<?php
declare(strict_types=1);

final class explorer_model extends model
{
    private const TABLES = ['explorer_transactions', 'explorer_blocks', 'explorer_state'];
    private const STATE_TABLE = 'explorer_transactions';
    private static ?array $requestStatusCache = null;

    public function databaseState(): string
    {
        $present = 0;
        foreach (self::TABLES as $table) { if ($this->tableExists($table)) { ++$present; } }
        $settingsPresent = $this->tableExists('explorer_settings');
        if ($present === 0 && !$settingsPresent) { return 'missing'; }
        $target = $this->targetVersion();
        if ($target === '') { return 'invalid'; }
        if ($this->tableExists(self::STATE_TABLE) && !$this->columnExists(self::STATE_TABLE, 'schema_version')) { return $this->patchFile('1.0.0', $target) !== null ? 'update' : 'invalid'; }
        if ($present !== count(self::TABLES)) { return 'invalid'; }
        $current = $this->schemaVersion();
        if ($current === null) { return 'invalid'; }
        if ($current === $target) { return $settingsPresent ? 'current' : 'invalid'; }
        return $this->patchFile($current, $target) !== null ? 'update' : 'invalid';
    }

    public function installSchema(): void { if ($this->databaseState() !== 'missing') { throw new RuntimeException('Fresh installation requires an absent module schema.'); } $this->executeSqlFile(__DIR__ . '/../sql/schema.sql'); }
    public function deleteData(): void { $this->query('DELETE FROM `explorer_state`'); $this->query('DELETE FROM `explorer_blocks`'); $this->query('DELETE FROM `explorer_transactions` WHERE `id` <> 1'); }
    public function configuration(): array { $row = $this->fetch('SELECT `primary_ip`, `primary_port`, `secondary_ip`, `secondary_port` FROM `explorer_settings` WHERE `id` = 1'); return is_array($row) ? $row : []; }

    public function saveConfiguration(array $input): void
    {
        $values = [];
        foreach (['primary', 'secondary'] as $name) {
            $ip = is_string($input[$name . '_ip'] ?? null) ? trim($input[$name . '_ip']) : ''; $port = $input[$name . '_port'] ?? '';
            if ($name === 'secondary' && $ip === '' && ($port === '' || $port === null)) { $values[$name . '_ip'] = null; $values[$name . '_port'] = null; continue; }
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) { throw new InvalidArgumentException('Enter a valid ' . $name . ' IP address and port (1–65535).'); }
            $values[$name . '_ip'] = $ip; $values[$name . '_port'] = (int) $port;
        }
        $this->query('INSERT INTO `explorer_settings` (`id`, `primary_ip`, `primary_port`, `secondary_ip`, `secondary_port`) VALUES (1, :primary_ip, :primary_port, :secondary_ip, :secondary_port) ON DUPLICATE KEY UPDATE `primary_ip` = VALUES(`primary_ip`), `primary_port` = VALUES(`primary_port`), `secondary_ip` = VALUES(`secondary_ip`), `secondary_port` = VALUES(`secondary_port`)', $values);
        self::$requestStatusCache = null;
    }

    public function stratumConfiguration(): array { $row = $this->fetch('SELECT `stratum_host`, `stratum_port` FROM `explorer_settings` WHERE `id` = 1'); return is_array($row) ? $row : []; }
    public function saveStratumConfiguration(array $input): void
    {
        $host = is_string($input['stratum_host'] ?? null) ? trim($input['stratum_host']) : ''; $port = $input['stratum_port'] ?? '';
        if ($host === '') { throw new InvalidArgumentException('Enter a Stratum host.'); }
        if (filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) { throw new InvalidArgumentException('Enter a valid Stratum port (1–65535).'); }
        $this->query('INSERT INTO `explorer_settings` (`id`, `stratum_host`, `stratum_port`) VALUES (1, :stratum_host, :stratum_port) ON DUPLICATE KEY UPDATE `stratum_host` = VALUES(`stratum_host`), `stratum_port` = VALUES(`stratum_port`)', ['stratum_host' => $host, 'stratum_port' => (int) $port]);
    }

    public function stratumStatus(string $schema): array
    {
        if ($schema !== 'current') { return ['state' => 'unconfigured', 'message' => 'Stratum not configured.']; }
        $configuration = $this->stratumConfiguration(); $host = trim((string) ($configuration['stratum_host'] ?? '')); $port = $configuration['stratum_port'] ?? null;
        if ($host === '' || !is_numeric($port) || (int) $port < 1 || (int) $port > 65535) { return ['state' => 'unconfigured', 'message' => 'Stratum not configured.']; }
        require_once __DIR__ . '/../libraries/stratum_status.php';
        try { return stratum_status::load($host, (int) $port); } catch (Throwable $exception) { return ['state' => 'unavailable', 'message' => 'Stratum status is temporarily unavailable.', 'diagnostics' => ['result' => 'exception', 'detail' => $exception->getMessage()]]; }
    }

    public function chainStatus(string $schema): array
    {
        if ($schema !== 'current') { return ['state' => 'unconfigured', 'message' => 'Blockchain not configured.']; }
        $configuration = $this->configuration(); if (empty($configuration['primary_ip'])) { return ['state' => 'unconfigured', 'message' => 'Blockchain not configured.']; }
        $cacheKey = json_encode($configuration); if (is_string($cacheKey) && isset(self::$requestStatusCache[$cacheKey])) { return self::$requestStatusCache[$cacheKey]; }
        require_once __DIR__ . '/../libraries/explorer_status.php'; $status = explorer_status::load($configuration);
        if (is_string($cacheKey)) { if (self::$requestStatusCache === null) { self::$requestStatusCache = []; } self::$requestStatusCache[$cacheKey] = $status; }
        return $status;
    }

    public function homeStatus(): array
    {
        $schema = $this->databaseState(); $status = $this->chainStatus($schema); $stratum = $this->stratumStatus($schema); $mining = $this->homeMiningStatus($stratum);
        if (($status['state'] ?? '') !== 'online' || !is_array($status['info'] ?? null)) { return ['available' => false, 'state' => (string) ($status['state'] ?? 'unavailable'), 'message' => (string) ($status['message'] ?? 'Blockchain status is temporarily unavailable.'), 'height' => null, 'block_count' => null, 'mining' => $mining]; }
        $info = $status['info']; return ['available' => true, 'state' => 'online', 'message' => (string) ($status['message'] ?? 'Blockchain connected.'), 'height' => isset($info['Chain height']) ? (string) $info['Chain height'] : null, 'block_count' => isset($info['Stored block count']) ? (string) $info['Stored block count'] : null, 'mining' => $mining];
    }

    public function latestBlock(): array
    {
        $status = $this->chainStatus($this->databaseState()); if (($status['state'] ?? '') !== 'online' || !is_array($status['info'] ?? null)) { return ['available' => false]; }
        $blockId = strtolower(trim((string) ($status['info']['Tip block ID'] ?? ''))); if (preg_match('/^[0-9a-f]{64}$/', $blockId) !== 1) { return ['available' => false]; }
        return ['available' => true, 'block_id' => $blockId, 'height' => isset($status['info']['Chain height']) ? (string) $status['info']['Chain height'] : null];
    }

    public function syncBlocks(): void
    {
        if ($this->databaseState() !== 'current') { return; }
        $status = $this->chainStatus('current'); if (($status['state'] ?? '') !== 'online' || !is_array($status['info'] ?? null)) { return; }
        $tipHeight = trim((string) ($status['info']['Chain height'] ?? '')); if (preg_match('/^[0-9]+$/', $tipHeight) !== 1 || strlen($tipHeight) > 18) { return; }
        $tip = (int) $tipHeight; $row = $this->fetch('SELECT MAX(`height`) AS `height` FROM `explorer_blocks`'); $start = is_array($row) && $row['height'] !== null ? ((int) $row['height'] + 1) : 0;
        if ($start > $tip) { return; }
        $client = $this->stncClient();
        for ($height = $start; $height <= $tip; ++$height) {
            $response = $client->getBlockByHeight($height); if (($response['status'] ?? -1) !== 0) { break; }
            $payload = (string) ($response['payload'] ?? ''); $block = $this->decodeBlock($payload);
            if ((int) $block['height'] !== $height) { throw new RuntimeException('STNC block height mismatch.'); }
            $blockId = $this->blockId($payload);
            $this->query('INSERT INTO `explorer_blocks` (`block_id`, `height`, `block_timestamp`, `transaction_count`) VALUES (:block_id, :height, :block_timestamp, :transaction_count) ON DUPLICATE KEY UPDATE `block_id` = VALUES(`block_id`), `block_timestamp` = VALUES(`block_timestamp`), `transaction_count` = VALUES(`transaction_count`)', ['block_id' => $blockId, 'height' => $height, 'block_timestamp' => (int) $block['timestamp'], 'transaction_count' => (int) $block['transaction_count']]);
        }
    }

    public function blocks(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit)); $statement = $this->query('SELECT `block_id`, `height`, `block_timestamp` AS `timestamp`, `transaction_count` FROM `explorer_blocks` ORDER BY `height` DESC LIMIT ' . $limit); return $statement->fetchAll();
    }

    public function blockById(string $blockId): ?array
    {
        $blockId = strtolower(trim($blockId)); if (preg_match('/^[0-9a-f]{64}$/', $blockId) !== 1) { return null; }
        $response = $this->stncClient()->getBlockById($blockId); if (($response['status'] ?? -1) === 6) { return null; } if (($response['status'] ?? -1) !== 0) { throw new RuntimeException('STNC block lookup failed.'); }
        $payload = (string) ($response['payload'] ?? ''); if (!hash_equals($blockId, $this->blockId($payload))) { throw new RuntimeException('STNC returned a block with a mismatched canonical ID.'); }
        return $this->decodeBlock($payload);
    }

    private function stncClient(): stnc_client
    {
        $configuration = $this->configuration(); $ip = trim((string) ($configuration['primary_ip'] ?? '')); $port = (int) ($configuration['primary_port'] ?? 0);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) { throw new RuntimeException('Explorer Chain endpoint is not configured.'); }
        require_once __DIR__ . '/../libraries/stnc_client.php'; return new stnc_client($ip, $port);
    }

    private function blockId(string $canonicalBlock): string
    {
        if (strlen($canonicalBlock) < 168 || substr($canonicalBlock, 0, 4) !== 'STNB') { throw new RuntimeException('Malformed canonical block.'); }
        return hash('sha256', "STN-CHAIN:BLOCK:ID:1\0" . substr($canonicalBlock, 0, 168));
    }

    private function decodeBlock(string $bytes): array
    {
        if (strlen($bytes) < 168 || substr($bytes, 0, 4) !== 'STNB') { throw new RuntimeException('Malformed canonical block.'); }
        $version = $this->u16(substr($bytes, 4, 2)); $networkId = bin2hex(substr($bytes, 8, 32)); $previous = bin2hex(substr($bytes, 40, 32)); $height = $this->u64Decimal(substr($bytes, 72, 8)); $timestamp = $this->u64Decimal(substr($bytes, 80, 8)); $commitment = bin2hex(substr($bytes, 88, 32)); $target = bin2hex(substr($bytes, 120, 32)); $nonce = $this->u64Decimal(substr($bytes, 152, 8)); $countFields = unpack('Ncount/Nbody', substr($bytes, 160, 8));
        if (!is_array($countFields)) { throw new RuntimeException('Malformed canonical block.'); } $bodyLength = (int) $countFields['body']; if ($bodyLength < 0 || strlen($bytes) !== 168 + $bodyLength) { throw new RuntimeException('Malformed canonical block length.'); }
        return ['version' => (string) $version, 'network_id' => $networkId, 'previous_block_id' => $previous, 'height' => $height, 'timestamp' => $timestamp, 'transaction_commitment' => $commitment, 'target' => $target, 'work_nonce' => $nonce, 'transaction_count' => (string) ((int) $countFields['count']), 'body_length' => (string) $bodyLength];
    }

    private function u16(string $bytes): int { $value = unpack('nvalue', $bytes); return is_array($value) ? (int) $value['value'] : 0; }
    private function u64Decimal(string $bytes): string { if (strlen($bytes) !== 8) { throw new RuntimeException('Malformed u64.'); } $decimal = '0'; for ($i = 0; $i < 8; ++$i) { $decimal = $this->decimalMulAdd($decimal, 256, ord($bytes[$i])); } return $decimal; }
    private function decimalMulAdd(string $decimal, int $multiplier, int $add): string { $carry = $add; $out = ''; for ($i = strlen($decimal) - 1; $i >= 0; --$i) { $value = (ord($decimal[$i]) - 48) * $multiplier + $carry; $out = (string) ($value % 10) . $out; $carry = intdiv($value, 10); } while ($carry > 0) { $out = (string) ($carry % 10) . $out; $carry = intdiv($carry, 10); } $trimmed = ltrim($out, '0'); return $trimmed === '' ? '0' : $trimmed; }

    private function homeMiningStatus(array $stratum): array
    {
        if (($stratum['state'] ?? '') !== 'online' || !is_array($stratum['info'] ?? null)) { return ['available' => false, 'running' => false, 'chain_connected' => false, 'work_available' => false, 'miners' => null, 'hashrate' => null]; }
        $info = $stratum['info']; return ['available' => true, 'running' => ($info['status'] ?? '') === 'running', 'chain_connected' => ($info['chain_connected'] ?? false) === true, 'work_available' => ($info['work_available'] ?? false) === true, 'miners' => isset($info['miners']) && is_int($info['miners']) ? $info['miners'] : null, 'hashrate' => isset($info['hashrate']) && is_int($info['hashrate']) ? $info['hashrate'] : null];
    }

    private function schemaVersion(): ?string { if (!$this->columnExists(self::STATE_TABLE, 'schema_version')) { return '1.0.0'; } $row = $this->fetch('SELECT `schema_version` FROM `' . self::STATE_TABLE . '` WHERE `id` = 1 LIMIT 1'); $version = is_array($row) ? trim((string) ($row['schema_version'] ?? '')) : ''; return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1 ? $version : null; }
    private function targetVersion(): string { $raw = file_get_contents(__DIR__ . '/../module.json'); $metadata = is_string($raw) ? json_decode($raw, true) : null; $version = is_array($metadata) ? trim((string) ($metadata['version'] ?? '')) : ''; return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1 ? $version : ''; }
    private function patchFile(string $current, string $target): ?string { $file = __DIR__ . '/../sql/patches/' . $current . '-to-' . $target . '.sql'; return is_file($file) && !is_link($file) ? $file : null; }
    private function executeSqlFile(string $file): void { $sql = is_file($file) ? file_get_contents($file) : false; if (!is_string($sql) || trim($sql) === '') { throw new RuntimeException('SQL file could not be read.'); } $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql); if (!is_array($statements)) { throw new RuntimeException('SQL file could not be parsed.'); } foreach ($statements as $statement) { $statement = trim($statement); if ($statement !== '') { $this->query($statement); } } }
    private function tableExists(string $table): bool { return (bool) $this->fetch('SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table_name LIMIT 1', ['schema' => DB_NAME, 'table_name' => $table]); }
    private function columnExists(string $table, string $column): bool { return (bool) $this->fetch('SELECT 1 FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table_name AND column_name = :column_name LIMIT 1', ['schema' => DB_NAME, 'table_name' => $table, 'column_name' => $column]); }
}
