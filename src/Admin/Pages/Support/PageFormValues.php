<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/** Coerces raw page-form values into what the model stores. */
final class PageFormValues
{
    /** @return array<string, string> */
    public static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $locale => $entry) {
            if (is_string($locale)) {
                $map[$locale] = (string) $entry;
            }
        }

        return $map;
    }

    /**
     * The relation picker clears to null/'' natively; PageFormOptions::NO_PARENT
     * stays tolerated here as a regression guard for the legacy select sentinel
     * (any still-cached client bundle, or a stored draft submitted from one).
     */
    public static function nullableInt(mixed $value): ?int
    {
        if (in_array($value, [null, '', PageFormOptions::NO_PARENT], true)) {
            return null;
        }

        return (int) $value;
    }

    public static function nullableMediaId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * The datetime field posts an ISO string or null (cleared) — see
     * dateField.tsx's DateTimeForm. An unparsable string is treated as
     * empty rather than throwing, matching the field's own clear semantics.
     */
    public static function nullableDateTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
