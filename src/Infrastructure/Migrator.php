<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $migrationDirectory,
    ) {
    }

    /**
     * @return list<string> Applied migration file names.
     */
    public function migrate(): array
    {
        $this->ensureMigrationTable();

        $files = glob(rtrim($this->migrationDirectory, '/') . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('Unable to enumerate database migrations.');
        }
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file);
            if ($this->isApplied($version)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException(sprintf('Unable to read migration %s.', $version));
            }

            foreach ($this->statements($sql) as $statement) {
                $this->db->exec($statement);
            }

            $insert = $this->db->prepare(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, UTC_TIMESTAMP())'
            );
            $insert->execute(['version' => $version]);
            $applied[] = $version;
        }

        return $applied;
    }

    private function ensureMigrationTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin'
        );
    }

    private function isApplied(string $version): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1'
        );
        $statement->execute(['version' => $version]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql);
        if ($lines === false) {
            throw new RuntimeException('Unable to parse migration.');
        }

        $withoutComments = [];
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $withoutComments[] = $line;
        }

        $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $withoutComments));
        if ($parts === false) {
            throw new RuntimeException('Unable to split migration statements.');
        }

        $statements = [];
        foreach ($parts as $part) {
            $statement = trim($part);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
