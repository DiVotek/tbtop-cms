<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

/**
 * Translates an enum-like value under "$keyPrefix.$value", falling back to
 * the raw value (title-cased) when no translation exists for it.
 */
final class EnumLabel
{
    public static function make(string $keyPrefix, mixed $value): string
    {
        $key = $keyPrefix . '.' . $value;
        $label = __($key);

        return $label === $key ? ucfirst((string) $value) : $label;
    }
}
