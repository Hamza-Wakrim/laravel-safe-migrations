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
                $override = $this->severities[$id] ?? null;

                if ($override === false) {
                    continue;
                }

                foreach ($rule->inspect($statement, $context) as $finding) {
                    $finding = $finding->forStatement($statement);

                    if (is_string($override)) {
                        $finding = $finding->withSeverity(Severity::from($override));
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
