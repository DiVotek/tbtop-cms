<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

/**
 * Builds the raw-HTML fragments the blocks showcase feeds to displayHtml():
 * the colored placeholder preview and the read-only fields table. Callers mark
 * them ->trusted(): the sanitizer would strip the inline styles, so every value
 * that originates from a block is escaped here; the colors and initials are
 * computed, never author-controlled.
 */
class BlockShowcaseHtml
{
    /**
     * A colored 16:9 (or given aspect) placeholder with the block initials and
     * a monospace kind tag — stands in until real preview images exist.
     */
    public static function placeholder(string $kind, string $label, string $aspect = '16 / 9', string $markSize = '2rem'): string
    {
        $hue = self::hue($kind);
        // Per-block colors live in inline CSS custom properties (--pb, --pb-dark)
        // on the element itself, so each card keeps its own hue. The dark-mode
        // <style> below is a single hue-independent rule (background:var(--pb-dark)
        // !important — needed to beat the inline background); duplicating it across
        // cards is harmless because every copy is byte-identical. The previous code
        // baked the hue into the global rule, so the last card's rule won and painted
        // every preview the same shade in dark mode.
        $bg = "hsl({$hue} 45% 92%)";
        $bgDark = "hsl({$hue} 30% 22%)";
        $fg = "hsl({$hue} 45% 40%)";
        $initials = self::e(self::initials($label));
        $tag = self::e($kind);

        return <<<HTML
            <div style="--pb:{$bg};--pb-dark:{$bgDark};position:relative;aspect-ratio:{$aspect};
                        display:grid;place-items:center;background:var(--pb);color:{$fg};
                        border-radius:inherit;"
                 data-block-preview>
                <span style="position:absolute;top:8px;left:8px;font-family:ui-monospace,monospace;
                             font-size:11px;padding:2px 7px;border-radius:5px;
                             background:rgba(255,255,255,.7);color:inherit;">{$tag}</span>
                <span style="font-family:ui-monospace,monospace;font-weight:700;font-size:{$markSize};
                             letter-spacing:-.02em;opacity:.85;">{$initials}</span>
            </div>
            <style>[data-theme="dark"] [data-block-preview]{background:var(--pb-dark) !important;}
            @media (prefers-color-scheme:dark){:root:not([data-theme="light"]) [data-block-preview]{background:var(--pb-dark) !important;}}</style>
            HTML;
    }

    /**
     * Read-only fields table: name (+ machine name), type, and required /
     * translatable flags.
     *
     * @param  list<array{name: string, label: string, type: string, required: bool, translatable: bool}>  $fields
     */
    public static function fieldsTable(array $fields, string $headName, string $headType, string $headFlags, string $requiredLabel, string $translatableLabel): string
    {
        $rows = '';
        foreach ($fields as $field) {
            $flags = [];
            if ($field['required']) {
                $flags[] = self::flag($requiredLabel, strong: true);
            }
            if ($field['translatable']) {
                $flags[] = self::flag($translatableLabel);
            }
            $flagsHtml = $flags === [] ? '<span style="opacity:.4">—</span>' : implode(' ', $flags);

            $rows .= '<tr style="border-top:1px solid var(--border)">'
                . '<td style="padding:9px 14px;font-weight:600">' . self::e($field['label'])
                . '<div style="font-family:ui-monospace,monospace;font-size:11.5px;font-weight:400;opacity:.6">' . self::e($field['name']) . '</div></td>'
                . '<td style="padding:9px 14px;font-family:ui-monospace,monospace;font-size:12px;opacity:.75">' . self::e($field['type']) . '</td>'
                . '<td style="padding:9px 14px">' . $flagsHtml . '</td>'
                . '</tr>';
        }

        $head = '<tr style="text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">'
            . '<th style="padding:9px 14px;font-weight:600">' . self::e($headName) . '</th>'
            . '<th style="padding:9px 14px;font-weight:600">' . self::e($headType) . '</th>'
            . '<th style="padding:9px 14px;font-weight:600">' . self::e($headFlags) . '</th>'
            . '</tr>';

        return '<div style="border:1px solid var(--border);border-radius:.5rem;overflow:hidden">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13.5px">'
            . '<thead style="background:var(--muted)">' . $head . '</thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table></div>';
    }

    /**
     * List of pages that use a block, as a bordered table with optional edit
     * links. Empty list renders a muted "not used yet" note instead.
     *
     * @param  list<array{name: string, url: string|null}>  $pages
     */
    public static function usageTable(array $pages, string $headPage, string $emptyLabel): string
    {
        if ($pages === []) {
            return '<p style="margin:0;font-size:13px;opacity:.6">' . self::e($emptyLabel) . '</p>';
        }

        $rows = '';
        foreach ($pages as $page) {
            $name = self::e($page['name']);
            $cell = $page['url'] !== null
                ? '<a href="' . self::e($page['url']) . '" style="color:inherit;text-decoration:underline;text-underline-offset:2px">' . $name . '</a>'
                : $name;
            $rows .= '<tr style="border-top:1px solid var(--border)">'
                . '<td style="padding:9px 14px">' . $cell . '</td></tr>';
        }

        $head = '<tr style="text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">'
            . '<th style="padding:9px 14px;font-weight:600">' . self::e($headPage) . '</th></tr>';

        return '<div style="border:1px solid var(--border);border-radius:.5rem;overflow:hidden">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13.5px">'
            . '<thead style="background:var(--muted)">' . $head . '</thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table></div>';
    }

    private static function flag(string $text, bool $strong = false): string
    {
        $style = $strong
            ? 'background:var(--foreground);color:var(--background);border:1px solid transparent'
            : 'border:1px solid var(--border);opacity:.75';

        return '<span style="font-size:10.5px;padding:1px 6px;border-radius:4px;' . $style . '">' . self::e($text) . '</span>';
    }

    private static function initials(string $label): string
    {
        $words = preg_split('/[\s\-_]+/', trim($label)) ?: [];
        $letters = array_slice(array_filter($words), 0, 2);
        $initials = implode('', array_map(static fn (string $w): string => mb_strtoupper(mb_substr($w, 0, 1)), $letters));

        return $initials === '' ? '?' : $initials;
    }

    /** Deterministic hue (0–359) from the kind string. */
    private static function hue(string $kind): int
    {
        $h = 0;
        foreach (str_split($kind) as $char) {
            $h = ($h * 31 + ord($char)) % 360;
        }

        return $h;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
