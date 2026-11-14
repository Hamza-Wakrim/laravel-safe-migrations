<?php

namespace HamzaWakrim\SafeMigrations\Attributes;

use Attribute;

/**
 * Mark a migration as intentionally doing something the checker flags.
 *
 *     return new #[AllowUnsafe('drop-column', reason: 'legacy_id unused since v2.3')] class extends Migration { ... };
 *
 * With no rules given, every rule is allowed for that migration.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AllowUnsafe
{
    /** @var list<string> */
    public readonly array $rules;

    /** @param  string|list<string>  $rules */
    public function __construct(string|array $rules = [], public readonly ?string $reason = null)
    {
        $this->rules = array_values((array) $rules);
    }

    public function allows(string $rule): bool
    {
        return $this->rules === [] || in_array($rule, $this->rules, true);
    }

    /** @return list<self> */
    public static function on(object $migration): array
    {
        return array_map(
            fn (\ReflectionAttribute $attribute) => $attribute->newInstance(),
            (new \ReflectionObject($migration))->getAttributes(self::class),
        );
    }
}
