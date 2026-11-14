<?php

namespace HamzaWakrim\SafeMigrations\Analysis;

use HamzaWakrim\SafeMigrations\Rules;
use HamzaWakrim\SafeMigrations\Rules\Rule;

final class Analyzer
{
    /** @var array<string, Rule> */
    private array $rules = [];

    /**
     * @param  iterable<Rule>  $rules
     * @param  array<string, string|false|null>  $severities  rule id => "error" | "warning" | false (disabled) | null (rule default)
     */
    public function __construct(iterable $rules, private readonly array $severities = [])
    {
        foreach ($rules as $rule) {
            $this->rules[$rule->id()] = $rule;
        }
    }

    /** @param  array<string, string|false|null>  $severities */
    public static function withDefaultRules(array $severities = []): self
    {
        return new self(self::defaultRules(), $severities);
    }

    /** @return list<Rule> */
    public static function defaultRules(): array
    {
        return [
            new Rules\DropTableRule,
            new Rules\DropColumnRule,
            new Rules\RenameTableRule,
            new Rules\RenameColumnRule,
            new Rules\AddNotNullColumnRule,
            new Rules\ChangeColumnRule,
            new Rules\SetNotNullRule,
            new Rules\BlockingIndexRule,
            new Rules\BlockingForeignKeyRule,
            new Rules\TableRebuildRule,
            new Rules\DataMutationRule,
        ];
    }

    /**
     * The severity a finding of this rule gets after config overrides, or null when the rule is turned off.
     */
    public function severityFor(string $rule, Severity $default): ?Severity
    {
        $override = $this->severities[$rule] ?? null;

        return match (true) {
            $override === false => null,
            is_string($override) => Severity::from($override),
            default => $default,
        };
    }

    /** @return list<string> */
    public function ruleIds(): array
    {
        return array_keys($this->rules);
    }

    /**
     * @param  iterable<Statement>  $statements
     * @return list<Finding>
     */
    public function analyze(iterable $statements, ?Context $context = null): array
    {
        $context ??= new Context;
        $findings = [];

        foreach ($statements as $statement) {
            foreach ($this->rules as $id => $rule) {
                if (($this->severities[$id] ?? null) === false) {
                    continue;
                }

                foreach ($rule->inspect($statement, $context) as $finding) {
                    $severity = $this->severityFor($id, $finding->severity);
                    $finding = $finding->forStatement($statement);

                    if ($severity !== null && $severity !== $finding->severity) {
                        $finding = $finding->withSeverity($severity);
                    }

                    // One finding per migration/rule/subject, e.g. PostgreSQL emits several clauses per ->change().
                    $findings[$statement->migration.'|'.$id.'|'.($finding->subject ?? $statement->normalized)] ??= $finding;
                }
            }

            $context->record($statement);
        }

        return array_values($findings);
    }
}
