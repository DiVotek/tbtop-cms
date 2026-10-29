<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

use Illuminate\Support\Facades\URL;

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

    /**
     * A link target: made absolute against the site, with the characters
     * that would end the markdown link (whitespace, parentheses, angle
     * brackets) percent-encoded.
     */
    public static function url(string $url): string
    {
        $url = URL::to(trim($url));

        return strtr($url, [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', "\t" => '%09', "\n" => '%0A', "\r" => '%0D']);
    }

    /**
     * Free text kept as written, minus heading markers and runs of blank
     * lines. Both heading forms go: `## x`, and a `===` / `---` underline
     * that would turn the line above it into an H1 or H2.
     */
    public static function block(string $text): string
    {
        $text = (string) preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', str_replace("\r\n", "\n", $text));
        $text = (string) preg_replace('/^[ \t]*(=+|-+)[ \t]*$/mu', '', $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
