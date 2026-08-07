<?php

declare(strict_types=1);

namespace Tbtop\Cms\Mail;

class Recipients
{
    /**
     * Splits on comma, semicolon, or newline; trims each piece; drops
     * anything that doesn't pass FILTER_VALIDATE_EMAIL. Accepts mixed
     * because callers read the raw value straight off Settings::get().
     *
     * @return list<string>
     */
    public static function parse(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $pieces = preg_split('/[,;\r\n]+/', $raw) ?: [];

        $emails = array_map(static fn (string $piece): string => trim($piece), $pieces);

        return array_values(array_filter(
            $emails,
            static fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }
}
