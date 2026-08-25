<?php

declare(strict_types=1);

namespace Tbtop\Cms\Richtext;

class RichtextNodeRenderer
{
    private const MAX_DEPTH = 64;

    private const MAX_NODES = 10000;

    /** @var array<string, string> */
    private const ALIGNMENTS = [
        'center' => 'center',
        'right' => 'right',
        'justify' => 'justify',
    ];

    public function __construct(
        private readonly RichtextTextRenderer $textRenderer = new RichtextTextRenderer,
        private readonly RichtextLinkRenderer $linkRenderer = new RichtextLinkRenderer,
    ) {}

    /** @param array<string, mixed> $node */
    public function renderChildren(array $node): string
    {
        $nodeCount = 0;

        return $this->renderChildrenWithinLimits($node, 0, $nodeCount);
    }

    /** @param array<string, mixed> $node */
    private function renderChildrenWithinLimits(array $node, int $depth, int &$nodeCount): string
    {
        if ($depth >= self::MAX_DEPTH) {
            return '';
        }

        $children = $node['children'] ?? null;

        if (! is_array($children)) {
            return '';
        }

        $html = '';

        foreach ($children as $child) {
            if (is_array($child)) {
                if ($nodeCount >= self::MAX_NODES) {
                    break;
                }

                $nodeCount++;
                $html .= $this->renderNode($child, $depth + 1, $nodeCount);
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $node */
    private function renderNode(array $node, int $depth, int &$nodeCount): string
    {
        $type = $node['type'] ?? null;

        return match ($type) {
            'paragraph' => $this->wrapBlock('p', $node, $depth, $nodeCount),
            'heading' => $this->renderHeading($node, $depth, $nodeCount),
            'quote' => $this->wrapBlock('blockquote', $node, $depth, $nodeCount),
            'list' => $this->renderList($node, $depth, $nodeCount),
            'listitem' => $this->wrapBlock('li', $node, $depth, $nodeCount),
            'code' => '<pre><code>' . $this->renderChildrenWithinLimits($node, $depth, $nodeCount) . '</code></pre>',
            'linebreak' => '<br>',
            'text' => $this->textRenderer->render($node),
            'link', 'autolink' => $this->linkRenderer->render($node, $this->renderChildrenWithinLimits($node, $depth, $nodeCount)),
            default => $this->renderChildrenWithinLimits($node, $depth, $nodeCount),
        };
    }

    /** @param array<string, mixed> $node */
    private function renderHeading(array $node, int $depth, int &$nodeCount): string
    {
        $tag = $node['tag'] ?? '';
        $tag = is_string($tag) && preg_match('/^h[1-6]$/', $tag) === 1 ? $tag : 'p';

        return $this->wrapBlock($tag, $node, $depth, $nodeCount);
    }

    /** @param array<string, mixed> $node */
    private function renderList(array $node, int $depth, int &$nodeCount): string
    {
        $isOrdered = ($node['listType'] ?? null) === 'number';
        $tag = $isOrdered ? 'ol' : 'ul';
        $start = $node['start'] ?? null;

        $attr = $isOrdered && is_int($start) && $start > 1 ? ' start="' . $start . '"' : '';

        return "<{$tag}{$attr}>" . $this->renderChildrenWithinLimits($node, $depth, $nodeCount) . "</{$tag}>";
    }

    /** @param array<string, mixed> $node */
    private function wrapBlock(string $tag, array $node, int $depth, int &$nodeCount): string
    {
        $attr = $this->alignmentAttribute($node);

        return "<{$tag}{$attr}>" . $this->renderChildrenWithinLimits($node, $depth, $nodeCount) . "</{$tag}>";
    }

    /** @param array<string, mixed> $node */
    private function alignmentAttribute(array $node): string
    {
        $format = $node['format'] ?? null;

        if (! is_string($format) || ! isset(self::ALIGNMENTS[$format])) {
            return '';
        }

        return ' style="text-align:' . self::ALIGNMENTS[$format] . '"';
    }
}
