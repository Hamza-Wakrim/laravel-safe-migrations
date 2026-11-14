<?php

namespace HamzaWakrim\SafeMigrations\Analysis;

use JsonSerializable;

final class Finding implements JsonSerializable
{
    public function __construct(
        public readonly string $rule,
        public readonly Severity $severity,
        public readonly string $message,
        public readonly ?string $suggestion = null,
        public readonly ?string $subject = null,
        public readonly ?string $sql = null,
        public readonly ?string $migration = null,
    ) {}

    public function withSeverity(Severity $severity): self
    {
        return new self($this->rule, $severity, $this->message, $this->suggestion, $this->subject, $this->sql, $this->migration);
    }

    public function forStatement(Statement $statement): self
    {
        return new self($this->rule, $this->severity, $this->message, $this->suggestion, $this->subject, $statement->sql, $statement->migration);
    }

    public function isError(): bool
    {
        return $this->severity === Severity::Error;
    }

    public function jsonSerialize(): array
    {
        return [
            'migration' => $this->migration,
            'rule' => $this->rule,
            'severity' => $this->severity->value,
            'subject' => $this->subject,
            'message' => $this->message,
            'suggestion' => $this->suggestion,
            'sql' => $this->sql,
        ];
    }
}
