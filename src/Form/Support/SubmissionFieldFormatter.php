<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Support;

/**
 * Formats one raw submission payload value into template-safe display text.
 * A custom HandlesFormSubmission block's payload shape isn't fixed (SPEC_V2
 * §3), so a field can be a scalar, an array (checkbox group), or an object —
 * arrays/objects are JSON-encoded rather than PHP-array-to-string'd, which
 * would otherwise silently drop data behind the word "Array".
 */
final class SubmissionFieldFormatter
{
    public static function format(mixed $value): string
    {
        if (! is_array($value) && ! is_object($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[unsupported value]' : $encoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function formatAll(array $payload): array
    {
        return array_map(self::format(...), $payload);
    }
}
