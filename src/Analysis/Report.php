<?php

namespace HamzaWakrim\SafeMigrations\Analysis;

use JsonSerializable;

final class Report implements JsonSerializable
{
    /**
     * @param  list<string>  $migrations  Names of the migrations that were analysed.
     * @param  list<Finding>  $findings
     * @param  list<Finding>  $suppressed  Findings allowed by #[AllowUnsafe] on the migration.
     */
    public function __construct(
        public readonly array $migrations,
        public readonly array $findings,
        public readonly array $suppressed = [],
    ) {}

    /** @return list<Finding> */
    public function errors(): array
    {
        return array_values(array_filter($this->findings, fn (Finding $f) => $f->severity === Severity::Error));
    }

    /** @return list<Finding> */
    public function warnings(): array
    {
        return array_values(array_filter($this->findings, fn (Finding $f) => $f->severity === Severity::Warning));
    }

    /** Whether the checked migrations should be blocked. Warnings only count in strict mode. */
    public function fails(bool $strict = false): bool
    {
        return $strict ? $this->findings !== [] : $this->errors() !== [];
    }

    /** @return array<string, list<Finding>> */
    public function byMigration(): array
    {
        $grouped = [];

        foreach ($this->findings as $finding) {
            $grouped[$finding->migration ?? '(unknown)'][] = $finding;
        }

        return $grouped;
    }

    public function jsonSerialize(): array
    {
        return [
            'migrations' => $this->migrations,
            'errors' => count($this->errors()),
            'warnings' => count($this->warnings()),
            'findings' => $this->findings,
            'suppressed' => $this->suppressed,
        ];
    }
}
