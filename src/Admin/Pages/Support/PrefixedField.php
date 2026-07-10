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
    public static function rename(Field $field, string $newName): Field
    {
        $node = $field->toNode();
        $clone = S::makeField($node->kind, $newName);

        foreach ($node->options as $key => $value) {
            if ($key === 'constraints' || $key === 'translatable') {
                continue;
            }
            $clone->set($key, $value);
        }

        foreach ($node->meta as $key => $value) {
            $clone->meta($key, $value);
        }

        if ($field->ruleEntries() !== []) {
            $clone->rules($field->ruleEntries());
        }

        if ($field->isTranslatableField()) {
            $clone->translatable();
        }

        foreach ($field->localeRuleEntries() as $locale => $rules) {
            $clone->rulesForLocale($locale, $rules);
        }

        return $clone;
    }
}
