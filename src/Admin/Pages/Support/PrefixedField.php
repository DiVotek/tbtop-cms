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
 * Known limitation: does not recurse into `childFields()` (repeater
 * sub-fields nested under the field itself, not a page-level repeater). No
 * shipped block uses a repeater field in its form(), so this is untested
 * territory rather than a silent bug — extend here if a block ever needs it.
 */
final class PrefixedField
{
    public static function rename(Field $field, string $newName, bool $stripRequired = false): Field
    {
        $node = $field->toNode();
        $clone = S::makeField($node->kind, $newName);

        foreach ($node->options as $key => $value) {
            if ($key === 'constraints' || $key === 'translatable') {
                continue;
            }
            // The required flag drives the asterisk; without the rule (below)
            // it would promise a validation that never comes.
            if ($stripRequired && $key === 'required') {
                continue;
            }
            $clone->set($key, $value);
        }

        foreach ($node->meta as $key => $value) {
            $clone->meta($key, $value);
        }

        $rules = $stripRequired ? self::withoutRequired($field->ruleEntries()) : $field->ruleEntries();
        if ($rules !== []) {
            $clone->rules($rules);
        }

        if ($field->isTranslatableField()) {
            $clone->translatable();
        }

        foreach ($field->localeRuleEntries() as $locale => $localeRules) {
            $localeRules = $stripRequired ? self::withoutRequired($localeRules) : $localeRules;
            if ($localeRules !== []) {
                $clone->rulesForLocale($locale, $localeRules);
            }
        }

        return $clone;
    }

    /**
     * Every rule except `required` (max:, regex:, email, ... all survive).
     * Used by the page editor's block tabs: any form-scoped action ("+ Add
     * block", "remove block") runs the WHOLE form's rules server-side and a
     * required-but-still-empty block field would veto it — silently, because
     * the admin client shows no validation feedback for actions (see
     * CMS_TASKS.md A7). Until the admin package validates on explicit submit
     * only, block fields must not hard-block the editor; emptiness is
     * signalled softly instead (blocks start inactive). Revert to hard
     * required once A7 lands.
     *
     * @param  list<string>  $rules
     * @return list<string>
     */
    private static function withoutRequired(array $rules): array
    {
        return array_values(array_filter($rules, static fn (string $rule): bool => $rule !== 'required'));
    }
}
