<?php

namespace HamzaWakrim\SafeMigrations\Support;

use Closure;
use HamzaWakrim\SafeMigrations\Exceptions\DirectPdoAccessException;
use PDO;
use PDOStatement;

/**
 * Stands in for a connection's PDO during the dry run.
 *
 * Laravel's pretend mode stops queries sent through the connection, but code
 * that talks to PDO directly (DB::getPdo()->exec(...)) would still reach the
 * database. This object answers metadata questions the schema grammars need
 * (such as the server version) from the real PDO, and refuses anything that
 * would execute SQL.
 */
final class DryRunPdo extends PDO
{
    private ?PDO $resolved = null;

    /**
     * The parent constructor is deliberately not called: this object never
     * opens a connection of its own, and every method Laravel uses is overridden.
     *
     * @param  PDO|Closure(): PDO  $real
     */
    public function __construct(private readonly PDO|Closure $real) {}

    public function exec(string $statement): int|false
    {
        throw DirectPdoAccessException::for($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        throw DirectPdoAccessException::for($query);
    }

    /** @param  array<int, mixed>  $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw DirectPdoAccessException::for($query);
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->real()->getAttribute($attribute);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return $this->real()->quote($string, $type);
    }

    public function lastInsertId(?string $name = null): string
    {
        return '0';
    }

    // Transactions are no-ops: nothing is written during the dry run.
    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    private function real(): PDO
    {
        return $this->resolved ??= $this->real instanceof Closure ? ($this->real)() : $this->real;
    }
}
