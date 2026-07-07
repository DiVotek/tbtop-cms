<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Render;

class RichtextNodeRenderer
{
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
        $children = $node['children'] ?? null;

        if (! is_array($children)) {
            return '';
        }

        $html = '';

        foreach ($children as $child) {
            if (is_array($child)) {
                $html .= $this->renderNode($child);
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $node */
    private function renderNode(array $node): string
    {
        $type = $node['type'] ?? null;

        return match ($type) {
            'paragraph' => $this->wrapBlock('p', $node),
            'heading' => $this->renderHeading($node),
            'quote' => $this->wrapBlock('blockquote', $node),
            'list' => $this->renderList($node),
            'listitem' => $this->wrapBlock('li', $node),
            'code' => '<pre><code>' . $this->renderChildren($node) . '</code></pre>',
            'linebreak' => '<br>',
            'text' => $this->textRenderer->render($node),
            'link', 'autolink' => $this->linkRenderer->render($node, $this->renderChildren($node)),
            default => $this->renderChildren($node),
        };
    }

    /** @param array<string, mixed> $node */
    private function renderHeading(array $node): string
    {
        $tag = $node['tag'] ?? '';
        $tag = is_string($tag) && preg_match('/^h[1-6]$/', $tag) === 1 ? $tag : 'p';

        return $this->wrapBlock($tag, $node);
    }

    /** @param array<string, mixed> $node */
    private function renderList(array $node): string
    {
        $isOrdered = ($node['listType'] ?? null) === 'number';
        $tag = $isOrdered ? 'ol' : 'ul';
        $start = $node['start'] ?? null;

        $attr = $isOrdered && is_int($start) && $start > 1 ? ' start="' . $start . '"' : '';

        return "<{$tag}{$attr}>" . $this->renderChildren($node) . "</{$tag}>";
    }

    /** @param array<string, mixed> $node */
    private function wrapBlock(string $tag, array $node): string
    {
        $attr = $this->alignmentAttribute($node);

        return "<{$tag}{$attr}>" . $this->renderChildren($node) . "</{$tag}>";
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
