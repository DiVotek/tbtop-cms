<?php

declare(strict_types=1);

namespace Tbtop\Cms\Telegram;

/**
 * Parses the comma/semicolon/newline-separated chat id list stored in
 * Settings ('notifications.telegram.chat_ids') — same shape as
 * Mail\Recipients, but chat ids are signed integers (a Telegram group id is
 * negative), not emails.
 *
 * A piece may carry a human label after '#' ("123456789 # Dima (@dima)") so
 * the admin can tell whose chat each id is; only the id part is ever sent
 * to Telegram. The invite flow writes such labels automatically.
 */
final class ChatIds
{
    /** @return list<string> */
    public static function parse(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $pieces = preg_split('/[,;\r\n]+/', $raw) ?: [];

        $ids = array_map(
            static fn (string $piece): string => trim(explode('#', $piece, 2)[0]),
            $pieces,
        );

        return array_values(array_unique(array_filter(
            $ids,
            static fn (string $id): bool => preg_match('/^-?\d+$/', $id) === 1,
        )));
    }

    /**
     * Appends one id (with an optional label) as a new line, keeping the
     * stored text — and any labels the admin already has — untouched. A
     * duplicate id is not added again.
     */
    public static function append(mixed $raw, string $id, ?string $label = null): string
    {
        $current = is_string($raw) ? trim($raw) : '';

        if (in_array($id, self::parse($current), true)) {
            return $current;
        }

        $label = $label === null ? '' : trim((string) preg_replace('/[#,;\r\n]+/', ' ', $label));
        $line = $label === '' ? $id : "{$id} # {$label}";

        return $current === '' ? $line : "{$current}\n{$line}";
    }
}
