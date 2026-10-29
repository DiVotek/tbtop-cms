<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Markdown;

use Illuminate\Support\Facades\URL;

/**
 * Markdown building blocks for a page's `.md` version, so that stored text
 * reads as text: an editor's `*` or `[` stays a character, and a line that
 * happens to start with `#` or `1.` does not become a heading or a list.
 */
final class Markdown
{
    /** Characters with inline meaning, backslash-escaped. */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]<>~|])/u', '\\\\$1', $text);
    }

    /**
     * Escapes a block marker at the start of any line: a heading, quote,
     * list item, setext underline or table/HTML start.
     */
    public static function escapeLineStart(string $text): string
    {
        $text = (string) preg_replace('/^([ \t]*)([#>+\-=])/mu', '$1\\\\$2', $text);

        return (string) preg_replace('/^([ \t]*\d+)([.)])/mu', '$1\\\\$2', $text);
    }

    /** Whitespace, newlines included, collapsed to single spaces. */
    public static function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Plain text as one escaped paragraph per blank-line-separated chunk. */
    public static function text(string $text): string
    {
        $paragraphs = preg_split('/\n\s*\n/u', str_replace("\r\n", "\n", trim($text))) ?: [];
        $out = [];

        foreach ($paragraphs as $paragraph) {
            $lines = array_map(static fn (string $line): string => self::escape(trim($line)), explode("\n", trim($paragraph)));
            $joined = self::escapeLineStart(implode("  \n", array_filter($lines, static fn (string $line): bool => $line !== '')));

            if ($joined !== '') {
                $out[] = $joined;
            }
        }

        return implode("\n\n", $out);
    }

    /** `## text`, levels clamped to 1..6; '' for empty text. */
    public static function heading(int $level, string $text): string
    {
        $text = self::inline($text);

        return $text === '' ? '' : str_repeat('#', max(1, min(6, $level))) . ' ' . self::escape($text);
    }

    public static function link(string $text, string $url): string
    {
        return '[' . self::escape(self::inline($text)) . '](' . self::url($url) . ')';
    }

    /**
     * A bulleted list, one item per line; items are taken as written
     * (already markdown) and empty ones are dropped.
     *
     * @param  list<string>  $items
     */
    public static function list(array $items): string
    {
        $lines = [];

        foreach ($items as $item) {
            $item = self::inline($item);

            if ($item !== '') {
                $lines[] = '- ' . $item;
            }
        }

        return implode("\n", $lines);
    }

    /** @param  string  $markdown  every line prefixed with `> ` */
    public static function quote(string $markdown): string
    {
        $markdown = trim($markdown);

        return $markdown === '' ? '' : (string) preg_replace('/^/mu', '> ', $markdown);
    }

    /** A fenced code block whose fence is longer than any backtick run inside. */
    public static function fence(string $text, string $info = ''): string
    {
        $fence = str_repeat('`', max(3, self::longestRun($text) + 1));

        return $fence . $info . "\n" . rtrim($text, "\n") . "\n" . $fence;
    }

    /** Inline code, delimited past any backtick run inside. */
    public static function code(string $text): string
    {
        $ticks = str_repeat('`', self::longestRun($text) + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';

        return $ticks . $pad . $text . $pad . $ticks;
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

    private static function longestRun(string $text): int
    {
        preg_match_all('/`+/', $text, $runs);

        return array_reduce($runs[0], static fn (int $max, string $run): int => max($max, strlen($run)), 0);
    }
}
