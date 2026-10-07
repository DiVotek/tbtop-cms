<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;

/**
 * Renames a Field for the per-block tabs on PageEditPage.
 *
 * Tbtop\Admin\Dsl\Fields\Field::$name is `public readonly`, set only in the
 * constructor, with no rename/clone-with-name API — by design, `S` is a
 * `final` class too, so neither can be wrapped or subclassed from the
 * consumer side. Renaming a field for namespacing therefore means
 * reconstructing a fresh instance of the same wire kind under the new name
 * and replaying every piece of configuration the original field carries:
 *
 * - `toNode()->options` (label, required flag, select options, columnSpan,
 *   etc.) minus the two derived keys (`constraints`, `translatable`), which
 *   are recomputed from source-of-truth state, not copied.
 * - `ruleEntries()` — the raw Laravel rule strings (`required`, `max:20`,
 *   `email`, ...). This is the piece that makes the reconstruction safe:
 *   `toNode()->options['constraints']` is a lossy wire projection (only
 *   required/min/max/regex/in survive), but `ruleEntries()` returns the
 *   actual rule list, so `RuleWalker` validates the prefixed field exactly
 *   as it would the original.
 * - `localeRuleEntries()` and the translatable flag, for per-locale rules.
 * - `toNode()->meta` (hiddenIf/disabledIf/custom meta).
 *
 * With $activeField given, every `required` (field-level and per-locale)
 * is rewritten to `required_unless:{activeField},false,0`: an inactive block
 * never renders, so its empty fields must not veto Save or the structural
 * add/remove actions. $requiredRule rewrites it to any other rule instead
 * (a subtype's section: `required_if:subtype,{key}`). The label keeps its
 * required marker. Only the server enforces the conditional rule;
 * ConstraintMap projects neither to the client, which keeps its pre-flight
 * from blocking on it.
 *
 * $excludeRule (`exclude_if:...`) goes first in the field-level and every
 * per-locale rule list: Laravel only skips the rules after it.
 *
 * Known limitation: does not recurse into `childFields()` (repeater
 * sub-fields nested under the field itself, not a page-level repeater). No
 * shipped block uses a repeater field in its form(), so this is untested
 * territory rather than a silent bug — extend here if a block ever needs it.
 */
final class PrefixedField
{
    public static function rename(Field $field, string $newName, ?string $activeField = null, ?string $requiredRule = null, ?string $excludeRule = null): Field
    {
        $requiredRule ??= $activeField === null ? null : "required_unless:{$activeField},false,0";
        $node = $field->toNode();
        // The field's own class, not S::makeField($node->kind): a field that
        // serializes as a built-in kind (Pages is a `select`) must come back as itself.
        $clone = $field::make($newName);

        foreach ($node->options as $key => $value) {
            if ($key === 'constraints' || $key === 'translatable') {
                continue;
            }
            $clone->set($key, $value);
        }

        foreach ($node->meta as $key => $value) {
            $clone->meta($key, $value);
        }

        $rules = self::gateRules($field->ruleEntries(), $requiredRule, $excludeRule);
        if ($rules !== []) {
            $clone->rules($rules);
        }

        if ($field->isTranslatableField()) {
            $clone->translatable();
        }

        foreach ($field->localeRuleEntries() as $locale => $localeRules) {
            if ($localeRules !== []) {
                $clone->rulesForLocale($locale, self::gateRules($localeRules, $requiredRule, $excludeRule));
            }
        }

        return $clone;
    }

    /**
     * `required_unless` with an absent or null other field still requires —
     * a payload that drops the active flag cannot sneak an empty field past.
     *
     * @param  list<string>  $rules
     * @return list<string>
     */
    private static function gateRules(array $rules, ?string $requiredRule, ?string $excludeRule): array
    {
        if ($requiredRule !== null) {
            $rules = array_map(
                static fn (string $rule): string => $rule === 'required' ? $requiredRule : $rule,
                $rules,
            );
        }

        return $excludeRule === null ? $rules : [$excludeRule, ...$rules];
    }
}
