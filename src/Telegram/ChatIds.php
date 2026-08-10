<?php

declare(strict_types=1);

namespace Tbtop\Cms\Telegram;

/**
 * Parses the comma/semicolon/newline-separated chat id list stored in
 * Settings ('notifications.telegram.chat_ids') — same shape as
 * Mail\Recipients, but chat ids are signed integers (a Telegram group id is
 * negative), not emails.
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

        $ids = array_map(static fn (string $piece): string => trim($piece), $pieces);

        return array_values(array_unique(array_filter(
            $ids,
            static fn (string $id): bool => preg_match('/^-?\d+$/', $id) === 1,
        )));
    }

    public static function append(mixed $raw, string $id): string
    {
        $existing = self::parse($raw);

        if (in_array($id, $existing, true)) {
            return implode("\n", $existing);
        }

        return implode("\n", [...$existing, $id]);
    }
}
