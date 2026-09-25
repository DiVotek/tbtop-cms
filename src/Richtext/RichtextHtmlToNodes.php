<?php

declare(strict_types=1);

namespace Tbtop\Cms\Richtext;

use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Legacy HTML string → Lexical nodes, as the admin editor's `$generateNodesFromDOM` reads it.
 * No markup passes through: only a link's `href` is read, and RichtextLinkRenderer validates it.
 */
class RichtextHtmlToNodes
{
    /** @var array<string, true> */
    private const DROPPED = [
        'script' => true, 'style' => true, 'template' => true, 'iframe' => true,
        'noscript' => true, 'object' => true, 'embed' => true, 'svg' => true,
        'math' => true, 'head' => true, 'title' => true, 'textarea' => true,
        'select' => true, 'canvas' => true, 'frame' => true, 'frameset' => true,
    ];

    /** Lexical text format bits, as RichtextTextRenderer reads them. */
    private const FORMATS = [
        'strong' => 1, 'b' => 1,
        'em' => 2, 'i' => 2,
        's' => 4,
        'u' => 8,
        'code' => 16,
    ];

    /** Block containers with no node of their own: their inline content becomes paragraphs, never joins a neighbour's. */
    private const CONTAINERS = [
        'div' => true, 'section' => true, 'article' => true, 'header' => true, 'footer' => true,
        'main' => true, 'aside' => true, 'nav' => true, 'figure' => true, 'figcaption' => true,
        'address' => true, 'details' => true, 'summary' => true, 'form' => true, 'fieldset' => true,
        'table' => true, 'caption' => true, 'tr' => true, 'td' => true, 'th' => true,
        'dl' => true, 'dt' => true, 'dd' => true,
    ];

    /** Node types that stand on their own; anything else is inline content. */
    private const BLOCKS = ['paragraph', 'heading', 'quote', 'list', 'listitem', 'code'];

    private int $nodeCount = 0;

    /** @return array{root: array{children: list<array<string, mixed>>}} */
    public function convert(string $html): array
    {
        $this->nodeCount = 0;

        // A bare fragment is an HTML5 parse error; its warnings would surface as exceptions.
        $body = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8')->body;

        $children = $body === null ? [] : $this->convertChildren($body, 0, 0);

        return ['root' => ['children' => $this->wrapInline($children, 'paragraph')]];
    }

    /**
     * `$depth` counts output nesting (blocks, links) only: format and unwrapped
     * elements add none, so deep inline markup keeps its text.
     *
     * @return list<array<string, mixed>>
     */
    private function convertChildren(Node $parent, int $format, int $depth): array
    {
        if ($depth >= RichtextNodeRenderer::MAX_DEPTH) {
            return [];
        }

        $nodes = [];

        foreach ($parent->childNodes as $child) {
            if ($this->nodeCount >= RichtextNodeRenderer::MAX_NODES) {
                break;
            }

            $this->nodeCount++;
            array_push($nodes, ...$this->convertNode($child, $format, $depth));
        }

        return $nodes;
    }

    /** @return list<array<string, mixed>> */
    private function convertNode(Node $node, int $format, int $depth): array
    {
        if ($node instanceof Text) {
            $text = $node->data;

            return $text === '' ? [] : [['type' => 'text', 'text' => $text, 'format' => $format]];
        }

        if (! $node instanceof Element) {
            return [];
        }

        $tag = $node->localName;

        if (isset(self::DROPPED[$tag])) {
            return [];
        }

        if (isset(self::FORMATS[$tag])) {
            return $this->convertChildren($node, $format | self::FORMATS[$tag], $depth);
        }

        return match ($tag) {
            'p' => [$this->block('paragraph', $node, $format, $depth)],
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => [['tag' => $tag, ...$this->block('heading', $node, $format, $depth)]],
            'blockquote' => [$this->block('quote', $node, $format, $depth)],
            'ul', 'ol' => [[
                'type' => 'list',
                'listType' => $tag === 'ol' ? 'number' : 'bullet',
                'children' => $this->wrapInline($this->convertChildren($node, $format, $depth + 1), 'listitem'),
            ]],
            'li' => [$this->block('listitem', $node, $format, $depth)],
            // One code node from the text alone: an inner <code> must not
            // also set the inline code bit, or it renders <pre><code><code>.
            'pre' => [[
                'type' => 'code',
                'children' => [['type' => 'text', 'text' => $node->textContent ?? '', 'format' => 0]],
            ]],
            'br' => [['type' => 'linebreak']],
            'a' => $this->link($node, $format, $depth),
            default => isset(self::CONTAINERS[$tag])
                ? $this->wrapInline($this->convertChildren($node, $format, $depth), 'paragraph')
                : $this->convertChildren($node, $format, $depth),
        };
    }

    /** @return array{type: string, children: list<array<string, mixed>>} */
    private function block(string $type, Element $element, int $format, int $depth): array
    {
        return ['type' => $type, 'children' => $this->convertChildren($element, $format, $depth + 1)];
    }

    /** @return list<array<string, mixed>> */
    private function link(Element $element, int $format, int $depth): array
    {
        $children = $this->convertChildren($element, $format, $depth + 1);
        $href = $element->getAttribute('href');

        if ($href === null || $href === '') {
            return $children;
        }

        foreach ($children as $child) {
            if ($this->isBlock($child)) {
                return $this->linkAround($children, $href);
            }
        }

        return [['type' => 'link', 'url' => $href, 'children' => $children]];
    }

    /**
     * A block inside a link is invalid nesting: the block is hoisted out and
     * the link moves inside it, around each inline run.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function linkAround(array $nodes, string $href): array
    {
        return $this->groupRuns(
            $nodes,
            fn (array $run): array => ['type' => 'link', 'url' => $href, 'children' => $run],
            fn (array $block): array => [...$block, 'children' => $this->linkAround($block['children'] ?? [], $href)],
        );
    }

    /**
     * Wraps each run of loose inline nodes in a `$wrapper` node, as Lexical does;
     * a whitespace-only run (the newlines between tags) is dropped.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function wrapInline(array $nodes, string $wrapper): array
    {
        return $this->groupRuns($nodes, fn (array $run): array => ['type' => $wrapper, 'children' => $run]);
    }

    /**
     * Maps each run of inline nodes through `$wrapRun` and each block through
     * `$mapBlock` (identity when null); whitespace-only runs are dropped.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  Closure(list<array<string, mixed>>): array<string, mixed>  $wrapRun
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $mapBlock
     * @return list<array<string, mixed>>
     */
    private function groupRuns(array $nodes, Closure $wrapRun, ?Closure $mapBlock = null): array
    {
        $result = [];
        $run = [];

        foreach ([...$nodes, null] as $node) {
            if ($node !== null && ! $this->isBlock($node)) {
                $run[] = $node;

                continue;
            }

            if (! $this->isBlank($run)) {
                $result[] = $wrapRun($run);
            }

            $run = [];

            if ($node !== null) {
                $result[] = $mapBlock === null ? $node : $mapBlock($node);
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $node */
    private function isBlock(array $node): bool
    {
        return in_array($node['type'], self::BLOCKS, true);
    }

    /** @param list<array<string, mixed>> $run */
    private function isBlank(array $run): bool
    {
        foreach ($run as $node) {
            if ($node['type'] !== 'text' || trim((string) $node['text']) !== '') {
                return false;
            }
        }

        return true;
    }
}
