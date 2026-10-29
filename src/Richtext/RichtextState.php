<?php

declare(strict_types=1);

namespace Tbtop\Cms\Richtext;

/**
 * A stored richtext value as a Lexical state: the state itself, its JSON,
 * a legacy HTML string, or plain text as one paragraph. Shared by the HTML
 * and markdown renderers so both read a value the same way.
 */
final class RichtextState
{
    /** The admin editor's own test for "this stored string is HTML" (richtextInitialState.ts). */
    private const HTML_PATTERN = '/^\s*<[a-z][a-z0-9]*[\s\/>]/i';

    /** @return array<string, mixed>|null The root node, or null for an empty or unreadable value. */
    public static function root(mixed $value, RichtextHtmlToNodes $htmlToNodes = new RichtextHtmlToNodes): ?array
    {
        $state = self::normalize($value, $htmlToNodes);
        $root = $state['root'] ?? null;

        return is_array($root) ? $root : null;
    }

    public static function isHtml(string $value): bool
    {
        return preg_match(self::HTML_PATTERN, $value) === 1;
    }

    /** @return array<array-key, mixed>|null */
    private static function normalize(mixed $value, RichtextHtmlToNodes $htmlToNodes): ?array
    {
        if (is_array($value) && isset($value['root'])) {
            return $value;
        }

        return is_string($value) ? self::normalizeString($value, $htmlToNodes) : null;
    }

    /** @return array<array-key, mixed>|null */
    private static function normalizeString(string $value, RichtextHtmlToNodes $htmlToNodes): ?array
    {
        $decoded = json_decode($value, true);

        if (is_array($decoded) && isset($decoded['root'])) {
            return $decoded;
        }

        if (trim($value) === '') {
            return null;
        }

        if (self::isHtml($value)) {
            return $htmlToNodes->convert($value);
        }

        return [
            'root' => [
                'children' => [
                    [
                        'type' => 'paragraph',
                        'children' => [
                            ['type' => 'text', 'text' => $value, 'format' => 0],
                        ],
                    ],
                ],
            ],
        ];
    }
}
