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

    public function databaseState(): string
    {
        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table)) {
                return 'missing';
            }
        }

        $current = $this->schemaVersion();
        $target = $this->targetVersion();

        if ($current === null || $target === '') {
            return 'invalid';
        }
        if ($current === $target) {
            return 'current';
        }

        return $this->patchFile($current, $target) !== null
            ? 'update'
            : 'invalid';
    }

    public function installSchema(): void
    {
        $this->executeSqlFile(__DIR__ . '/../sql/schema.sql');
    }

    public function updateSchema(): void
    {
        $current = $this->schemaVersion();
        $target = $this->targetVersion();
        $patch = $current === null ? null : $this->patchFile($current, $target);

        if ($patch === null) {
            throw new RuntimeException('No valid schema migration path exists.');
        }

        $this->executeSqlFile($patch);
        $this->query(
            'UPDATE `' . self::STATE_TABLE . '` SET `schema_version` = :version WHERE `id` = 1',
            ['version' => $target]
        );
    }

    public function deleteData(): void
    {
        $this->query('DELETE FROM `explorer_state`');
        $this->query('DELETE FROM `explorer_blocks`');
        $this->query('DELETE FROM `explorer_transactions` WHERE `id` <> 1');
    }

    private function schemaVersion(): ?string
    {
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
}
