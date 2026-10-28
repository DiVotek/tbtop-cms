<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

/**
 * The few markdown rules llms.txt needs, so that editor text cannot break
 * the file's structure: a link name cannot close its brackets early, a
 * one-line field cannot start a new list item, and free text cannot open a
 * heading (the format reserves H1 for the site name and H2 for file lists).
 */
final class LlmsTxtMarkdown
{
    /** Whitespace, newlines included, collapsed to single spaces. */
    public static function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function linkText(string $text): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], self::inline($text));
    }

    /** Free text kept as written, minus heading markers and runs of blank lines. */
    public static function block(string $text): string
    {
        $text = (string) preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', str_replace("\r\n", "\n", $text));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
