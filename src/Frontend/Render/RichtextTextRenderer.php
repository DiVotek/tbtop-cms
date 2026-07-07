<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Render;

class RichtextTextRenderer
{
    /** @var list<array{int, string}> */
    private const TAGS = [
        [1, 'strong'],
        [2, 'em'],
        [16, 'code'],
        [8, 'u'],
        [4, 's'],
        [32, 'sub'],
        [64, 'sup'],
    ];

    /** @param array<string, mixed> $node */
    public function render(array $node): string
    {
        $text = $node['text'] ?? '';
        $text = is_string($text) ? $text : '';

        $html = htmlspecialchars($text, ENT_QUOTES);

        $format = $node['format'] ?? 0;
        $format = is_int($format) ? $format : 0;

        foreach (self::TAGS as [$bit, $tag]) {
            if (($format & $bit) !== 0) {
                $html = "<{$tag}>{$html}</{$tag}>";
            }
        }

        return $html;
    }
}
