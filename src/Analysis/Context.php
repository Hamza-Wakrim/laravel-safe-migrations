<?php

namespace HamzaWakrim\SafeMigrations\Analysis;

/**
 * Tracks schema state across the statements of one check run, so operations on
 * tables created earlier in the same run (which hold no production data yet)
 * are not reported.
 */
final class Context
{
    /** @var array<string, true> */
    private array $created = [];

    /** @var array<string, true> "table.column" for columns added earlier in the same run. */
    private array $addedColumns = [];

    /** @var array<string, true> Migrations that set a lock timeout for their session. */
    private array $lockTimeouts = [];

    /** @var array<string, true> Tables SQLite is currently rebuilding via __temp__ copies. */
    private array $rebuilding = [];

    public function isNew(string $table): bool
    {
        return isset($this->created[$table]);
    }

    public function isRebuilding(string $table): bool
    {
        return isset($this->rebuilding[$table]);
    }

    public function isNewColumn(string $table, string $column): bool
    {
        return $this->isNew($table) || isset($this->addedColumns["{$table}.{$column}"]);
    }

    /** Whether this statement's migration set a lock timeout before it. */
    public function hasLockTimeout(Statement $statement): bool
    {
        return isset($this->lockTimeouts[(string) $statement->migration]);
    }

    public function record(Statement $statement): void
    {
        $sql = $statement->normalized;

        // SET lock_timeout (PostgreSQL) / SET SESSION lock_wait_timeout (MySQL, MariaDB). 0 means wait forever.
        if (preg_match('/^set (?:session |local )?(?:@@(?:session\.|local\.)?)?(?:lock_timeout|lock_wait_timeout) ?(?:=|to) ?\'?([^\s,\']+)/', $sql, $m)) {
            if (preg_match('/^0+(?:\.0+)?(?:ms|s|min)?$/', $m[1])) {
                unset($this->lockTimeouts[(string) $statement->migration]);
            } else {
                $this->lockTimeouts[(string) $statement->migration] = true;
            }

            return;
        }

        if (preg_match('/^create table (?:if not exists )?([\w.]+)/', $sql, $m)) {
            if (str_starts_with($m[1], '__temp__')) {
                $this->rebuilding[substr($m[1], 8)] = true;
            } else {
                $this->created[$m[1]] = true;
            }

            return;
        }

        if (preg_match('/^alter table __temp__(\w+) rename to /', $sql, $m)) {
            unset($this->rebuilding[$m[1]]);

            return;
        }

        if (preg_match('/^drop table (?:if exists )?([\w.]+)/', $sql, $m) && ! $this->isRebuilding($m[1])) {
            unset($this->created[$m[1]]);

            return;
        }

        if (preg_match('/^(?:alter table ([\w.]+) rename to|rename table ([\w.]+) to) ([\w.]+)/', $sql, $m)) {
            $from = $m[1] !== '' ? $m[1] : $m[2];

            if ($this->isNew($from)) {
                unset($this->created[$from]);
                $this->created[$m[3]] = true;
            }
        }

        if (($alter = $statement->alterTable()) !== null) {
            foreach ($alter[1] as $clause) {
                if (preg_match('/^add (?:column )?(?:if not exists )?(?!(?:index|unique|primary|constraint|foreign|key|fulltext|spatial|check|partition)\b)(\w+) /', $clause, $m)) {
                    $this->addedColumns["{$alter[0]}.{$m[1]}"] = true;
                }
            }
        }
    }
}
