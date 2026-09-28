<?php
declare(strict_types=1);

final class explorer_model extends model
{
    private const TABLES = [
        'explorer_transactions',
        'explorer_blocks',
        'explorer_state',
    ];
    private const STATE_TABLE = 'explorer_transactions';

    /** @var array<string,array>|null */
    private static ?array $requestStatusCache = null;

    public function databaseState(): string
    {
        $present = 0;
        foreach (self::TABLES as $table) {
            if ($this->tableExists($table)) { ++$present; }
        }
        if ($present === 0 && !$this->tableExists('explorer_settings')) { return 'missing'; }
        if ($present !== count(self::TABLES)) { return 'invalid'; }

        $current = $this->schemaVersion();
        $target = $this->targetVersion();

        if ($current === null || $target === '') {
            return 'invalid';
        }
        if ($current === $target) {
            return $this->tableExists('explorer_settings') ? 'current' : 'invalid';
        }

        return $this->patchFile($current, $target) !== null
            ? 'update'
            : 'invalid';
    }

    public function installSchema(): void
    {
        if ($this->databaseState() !== 'missing') {
            throw new RuntimeException('Fresh installation requires an absent module schema.');
        }
        $this->executeSqlFile(__DIR__ . '/../sql/schema.sql');
    }

    public function deleteData(): void
    {
        $this->query('DELETE FROM `explorer_state`');
        $this->query('DELETE FROM `explorer_blocks`');
        $this->query('DELETE FROM `explorer_transactions` WHERE `id` <> 1');
    }

    public function configuration(): array
    {
        $row = $this->fetch('SELECT `primary_ip`, `primary_port`, `secondary_ip`, `secondary_port` FROM `explorer_settings` WHERE `id` = 1');
        return is_array($row) ? $row : [];
    }

    public function saveConfiguration(array $input): void
    {
        $values = [];
        foreach (['primary', 'secondary'] as $name) {
            $ip = is_string($input[$name . '_ip'] ?? null) ? trim($input[$name . '_ip']) : '';
            $port = $input[$name . '_port'] ?? '';
            if ($name === 'secondary' && $ip === '' && ($port === '' || $port === null)) {
                $values[$name . '_ip'] = null;
                $values[$name . '_port'] = null;
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP) === false
                || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
                throw new InvalidArgumentException('Enter a valid ' . $name . ' IP address and port (1–65535).');
            }
            $values[$name . '_ip'] = $ip;
            $values[$name . '_port'] = (int) $port;
        }
        $this->query('INSERT INTO `explorer_settings` (`id`, `primary_ip`, `primary_port`, `secondary_ip`, `secondary_port`) '
            . 'VALUES (1, :primary_ip, :primary_port, :secondary_ip, :secondary_port) ON DUPLICATE KEY UPDATE '
            . '`primary_ip` = VALUES(`primary_ip`), `primary_port` = VALUES(`primary_port`), '
            . '`secondary_ip` = VALUES(`secondary_ip`), `secondary_port` = VALUES(`secondary_port`)', $values);
        self::$requestStatusCache = null;
    }

    public function chainStatus(string $schema): array
    {
        if ($schema !== 'current') {
            return ['state' => 'unconfigured', 'message' => 'Blockchain not configured.'];
        }
        $configuration = $this->configuration();
        if (empty($configuration['primary_ip'])) {
            return ['state' => 'unconfigured', 'message' => 'Blockchain not configured.'];
        }

        $cacheKey = json_encode($configuration);
        if (is_string($cacheKey) && isset(self::$requestStatusCache[$cacheKey])) {
            return self::$requestStatusCache[$cacheKey];
        }

        require_once __DIR__ . '/../libraries/explorer_status.php';
        $status = explorer_status::load($configuration);

        if (is_string($cacheKey)) {
            if (self::$requestStatusCache === null) {
                self::$requestStatusCache = [];
            }
            self::$requestStatusCache[$cacheKey] = $status;
        }

        return $status;
    }

    /**
     * Bounded status contract consumed by the Home module.
     * Observation failure is represented as unavailable, never as proof that
     * STN Chain itself is offline.
     */
    public function homeStatus(): array
    {
        $status = $this->chainStatus($this->databaseState());
        if (($status['state'] ?? '') !== 'online' || !is_array($status['info'] ?? null)) {
            return [
                'available' => false,
                'state' => (string) ($status['state'] ?? 'unavailable'),
                'message' => (string) ($status['message'] ?? 'Blockchain status is temporarily unavailable.'),
                'height' => null,
                'block_count' => null,
                'mining' => ['available' => false],
            ];
        }

        $info = $status['info'];
        return [
            'available' => true,
            'state' => 'online',
            'message' => (string) ($status['message'] ?? 'Blockchain connected.'),
            'height' => isset($info['Chain height']) ? (string) $info['Chain height'] : null,
            'block_count' => isset($info['Stored block count']) ? (string) $info['Stored block count'] : null,
            'mining' => ['available' => false],
        ];
    }

    /**
     * Return the latest observed Chain tip without opening a second connection
     * when homeStatus() has already observed INFO during this request.
     */
    public function latestBlock(): array
    {
        $status = $this->chainStatus($this->databaseState());
        if (($status['state'] ?? '') !== 'online' || !is_array($status['info'] ?? null)) {
            return ['available' => false];
        }

        $blockId = strtolower(trim((string) ($status['info']['Tip block ID'] ?? '')));
        if (preg_match('/^[0-9a-f]{64}$/', $blockId) !== 1) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'block_id' => $blockId,
            'height' => isset($status['info']['Chain height'])
                ? (string) $status['info']['Chain height']
                : null,
        ];
    }

    private function schemaVersion(): ?string
    {
        /*
         * Explorer 1.0 deployments predate the schema_version column.
         * Never query a column until its presence has been established: doing
         * so turns a normal module migration state into a PDO exception.
         */
        if (!$this->columnExists(self::STATE_TABLE, 'schema_version')) {
            return $this->tableExists('explorer_settings')
                ? $this->targetVersion()
                : '1.0.0';
        }

        $row = $this->fetch(
            'SELECT `schema_version` FROM `' . self::STATE_TABLE . '` WHERE `id` = 1 LIMIT 1'
        );
        $version = is_array($row) ? trim((string) ($row['schema_version'] ?? '')) : '';

        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1
            ? $version
            : null;
    }

    private function targetVersion(): string
    {
        $raw = file_get_contents(__DIR__ . '/../module.json');
        $metadata = is_string($raw) ? json_decode($raw, true) : null;
        $version = is_array($metadata) ? trim((string) ($metadata['version'] ?? '')) : '';

        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1
            ? $version
            : '';
    }

    private function patchFile(string $current, string $target): ?string
    {
        $file = __DIR__ . '/../sql/patches/' . $current . '-to-' . $target . '.sql';
        return is_file($file) && !is_link($file) ? $file : null;
    }

    private function executeSqlFile(string $file): void
    {
        $sql = is_file($file) ? file_get_contents($file) : false;
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('SQL file could not be read.');
        }
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
        if (!is_array($statements)) {
            throw new RuntimeException('SQL file could not be parsed.');
        }
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->query($statement);
            }
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->fetch(
            'SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema = :schema AND table_name = :table_name LIMIT 1',
            ['schema' => DB_NAME, 'table_name' => $table]
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->fetch(
            'SELECT 1 FROM information_schema.columns '
            . 'WHERE table_schema = :schema AND table_name = :table_name '
            . 'AND column_name = :column_name LIMIT 1',
            [
                'schema' => DB_NAME,
                'table_name' => $table,
                'column_name' => $column,
            ]
        );
    }
}
