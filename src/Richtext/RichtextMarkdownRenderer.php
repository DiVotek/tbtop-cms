<?php

declare(strict_types=1);

namespace Tbtop\Cms\Richtext;

use Tbtop\Cms\Frontend\Markdown\Markdown;

/**
 * A stored richtext value as markdown, for the page's `.md` version. Reads
 * the same Lexical nodes RichtextNodeRenderer turns into HTML, under the same
 * depth and node limits. Links keep only the URLs RichtextLinkRenderer
 * allows; anything else is reduced to its text. Headings start at H2: the
 * document's H1 is the page heading.
 */
class RichtextMarkdownRenderer
{
    /** Lexical text format bits, outermost last, with their markdown markers. */
    private const array FORMATS = [
        [16, '`'],
        [1, '**'],
        [2, '_'],
        [4, '~~'],
    ];

    private int $nodeCount = 0;

    public function __construct(
        private readonly RichtextLinkRenderer $links = new RichtextLinkRenderer,
        private readonly RichtextHtmlToNodes $htmlToNodes = new RichtextHtmlToNodes,
    ) {}

    public function render(mixed $value): string
    {
        $root = RichtextState::root($value, $this->htmlToNodes);

        if ($root === null) {
            return '';
        }

        $this->nodeCount = 0;

        return trim($this->blocks($root, 0));
    }

    /** @param array<string, mixed> $node Block-level children, one blank line apart. */
    private function blocks(array $node, int $depth): string
    {
        $parts = [];

        foreach ($this->children($node, $depth) as $child) {
            $part = $this->block($child, $depth + 1);

            if (trim($part) !== '') {
                $parts[] = $part;
            }
        }

        return implode("\n\n", $parts);
    }

    /** @param array<string, mixed> $node */
    private function block(array $node, int $depth): string
    {
        return match ($node['type'] ?? null) {
            'paragraph' => Markdown::escapeLineStart($this->inline($node, $depth)),
            'heading' => $this->heading($node, $depth),
            'quote' => Markdown::quote($this->inline($node, $depth)),
            'list' => $this->list($node, $depth, 0),
            'code' => $this->code($node, $depth),
            // A text or link node straight under the root.
            default => $this->node($node, $depth),
        };
    }

    /** @param array<string, mixed> $node */
    private function heading(array $node, int $depth): string
    {
        $tag = $node['tag'] ?? '';
        $level = is_string($tag) && preg_match('/^h([1-6])$/', $tag, $match) === 1 ? max(2, (int) $match[1]) : 0;
        $text = Markdown::inline($this->inline($node, $depth));

        if ($text === '') {
            return '';
        }

        return $level === 0 ? Markdown::escapeLineStart($text) : str_repeat('#', $level) . ' ' . $text;
    }

    /** @param array<string, mixed> $node */
    private function list(array $node, int $depth, int $indent): string
    {
        $ordered = ($node['listType'] ?? null) === 'number';
        $start = $node['start'] ?? 1;
        $number = is_int($start) && $start > 0 ? $start : 1;
        $pad = str_repeat(' ', $indent);
        $lines = [];

        foreach ($this->children($node, $depth) as $item) {
            [$text, $nested] = $this->listItem($item, $depth + 1, $indent + ($ordered ? 3 : 2));

            if ($text !== '') {
                $lines[] = $pad . ($ordered ? ($number++) . '. ' : '- ') . $text;
            }

            array_push($lines, ...$nested);
        }

        return implode("\n", $lines);
    }

    /**
     * The item's own text, and the lines of the lists nested in it (Lexical
     * nests a list inside a list item of its own).
     *
     * @param  array<string, mixed>  $item
     * @return array{string, list<string>}
     */
    private function listItem(array $item, int $depth, int $indent): array
    {
        $text = '';
        $nested = [];

        foreach ($this->children($item, $depth) as $child) {
            if (($child['type'] ?? null) === 'list') {
                $nested = [...$nested, ...explode("\n", $this->list($child, $depth + 1, $indent))];

                continue;
            }

            $text .= $this->node($child, $depth + 1);
        }

        return [Markdown::escapeLineStart(Markdown::inline($text)), array_values(array_filter($nested, static fn (string $line): bool => trim($line) !== ''))];
    }

    /** @param array<string, mixed> $node */
    private function code(array $node, int $depth): string
    {
        $text = '';

        foreach ($this->children($node, $depth) as $child) {
            $text .= ($child['type'] ?? null) === 'linebreak' ? "\n" : (is_string($child['text'] ?? null) ? $child['text'] : '');
        }

        return Markdown::fence($text);
    }

    /** @param array<string, mixed> $node Inline children joined into one string. */
    private function inline(array $node, int $depth): string
    {
        $text = '';

        foreach ($this->children($node, $depth) as $child) {
            $text .= $this->node($child, $depth + 1);
        }

        return $text;
    }

    /** @param array<string, mixed> $node */
    private function node(array $node, int $depth): string
    {
        return match ($node['type'] ?? null) {
            'text' => $this->text($node),
            'linebreak' => "  \n",
            'link', 'autolink' => $this->link($node, $this->inline($node, $depth)),
            default => $this->inline($node, $depth),
        };
    }

    /** @param array<string, mixed> $node */
    private function text(array $node): string
    {
        $text = is_string($node['text'] ?? null) ? $node['text'] : '';
        $format = is_int($node['format'] ?? null) ? $node['format'] : 0;

        if (trim($text) === '') {
            return $text;
        }

        if (($format & 16) !== 0) {
            return Markdown::code($text);
        }

        // Markers hug the words: `** bold **` is not bold in markdown.
        preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $parts);
        $inner = Markdown::escape($parts[2]);

        foreach (self::FORMATS as [$bit, $marker]) {
            if ($bit !== 16 && ($format & $bit) !== 0) {
                $inner = $marker . $inner . $marker;
            }
        }

        return $parts[1] . $inner . $parts[3];
    }

    /** @param array<string, mixed> $node */
    private function link(array $node, string $text): string
    {
        $url = is_string($node['url'] ?? null) ? trim($node['url']) : '';

        if ($url === '' || ! $this->links->isAllowedUrl($url) || trim($text) === '') {
            return $text;
        }

        return '[' . $text . '](' . Markdown::url($url) . ')';
    }

    /**
     * The node's array children, within the renderer's limits: nothing past
     * MAX_DEPTH, and no more than MAX_NODES nodes in one value.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function children(array $node, int $depth): array
    {
        $children = $node['children'] ?? null;

        if ($depth >= RichtextNodeRenderer::MAX_DEPTH || ! is_array($children)) {
            return [];
        }

        $out = [];

        foreach ($children as $child) {
            if (! is_array($child) || $this->nodeCount >= RichtextNodeRenderer::MAX_NODES) {
                continue;
            }

            $this->nodeCount++;
            /** @var array<string, mixed> $child */
            $out[] = $child;
        }

        return $out;
    }
}
