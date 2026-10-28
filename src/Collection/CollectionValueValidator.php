<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection;

use Illuminate\Support\Facades\Validator;
use Tbtop\Cms\Admin\Fields\CollectionField;
use Tbtop\Cms\Collection\Models\Entry;

/**
 * Named rule `collection_value:<collection>,<cap>,<mode+mode…>` for a
 * `collection` field. A named validator, not a Rule object: Field collects
 * rules as `list<string>` and PrefixedField replays them.
 */
final class CollectionValueValidator
{
    public const string RULE = 'collection_value';

    public static function register(): void
    {
        Validator::extend(
            self::RULE,
            static fn (string $attribute, mixed $value, array $parameters): bool => self::passes($value, $parameters),
        );

        Validator::replacer(self::RULE, static fn (): string => (string) __('tbtop-cms::collections.field.invalid'));
    }

    public static function rule(CollectionField $field): string
    {
        $modes = array_map(static fn (PickMode $mode): string => $mode->value, $field->allowedModes());

        return self::RULE . ':' . implode(',', [$field->collectionKey(), (string) ($field->limitCap() ?? 0), implode('+', $modes)]);
    }

    /** @param  list<string>  $parameters */
    public static function passes(mixed $value, array $parameters): bool
    {
        if ($value === null || $value === []) {
            return true;
        }
        if (! is_array($value) || ! is_string($value['mode'] ?? null)) {
            return false;
        }

        [$collection, $cap, $modes] = array_pad($parameters, 3, '');
        $mode = PickMode::tryFrom($value['mode']);
        if ($mode === null || ! in_array($mode->value, explode('+', $modes), true)) {
            return false;
        }

        $cap = (int) $cap;

        return match ($mode) {
            PickMode::Latest => self::limitIsValid($value['limit'] ?? null, $cap),
            PickMode::Manual => self::idsAreValid($value['ids'] ?? null, $collection, $cap),
            default => true,
        };
    }

    private static function limitIsValid(mixed $limit, int $cap): bool
    {
        if ($limit === null) {
            return true;
        }
        $isInt = is_int($limit) || (is_string($limit) && ctype_digit($limit));

        return $isInt && (int) $limit >= 1 && ($cap === 0 || (int) $limit <= $cap);
    }

    /** Drafts may be picked: an editor lines up entries before publishing them; the site skips them until then. */
    private static function idsAreValid(mixed $raw, string $collection, int $cap): bool
    {
        if ($raw === null) {
            return true;
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            return false;
        }
        $ids = CollectionValue::ids($raw);
        if (count($ids) !== count($raw) || ($cap !== 0 && count($ids) > $cap)) {
            return false;
        }

        return $ids === [] || Entry::query()->of($collection)->whereKey($ids)->count() === count($ids);
    }
}
