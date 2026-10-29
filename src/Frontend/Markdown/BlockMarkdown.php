<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Markdown;

use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\ResolvedBlock;
use Tbtop\Cms\Richtext\RichtextState;
use Throwable;

/**
 * A resolved block as markdown: its own toMarkdown(), else the text of its
 * translatable fields. A block whose toMarkdown() throws is left out with a
 * warning, as a failing resolve() is; with `tbtop-cms.blocks.strict` the
 * exception propagates.
 */
final readonly class BlockMarkdown
{
    public function __construct(private BlockRegistry $registry) {}

    public function render(ResolvedBlock $resolved, MarkdownContext $ctx): string
    {
        if (! $this->registry->has($resolved->kind)) {
            return '';
        }

        $block = $this->registry->get($resolved->kind);

        try {
            $own = $block->toMarkdown($resolved->props, $ctx);
        } catch (Throwable $e) {
            if ((bool) config('tbtop-cms.blocks.strict', false)) {
                throw $e;
            }

            Log::warning('tbtop-cms: block toMarkdown() failed', ['kind' => $resolved->kind, 'id' => $resolved->id, 'exception' => $e]);

            return '';
        }

        return trim($own ?? $this->fallback($resolved->props, array_flip($block->translatableFields()), '', $ctx));
    }

    /**
     * Walks the props in order and keeps the strings at translatable paths;
     * list indices are not part of a path (`items.question`), so repeater
     * rows come out row by row.
     *
     * @param  array<string, int>  $paths
     */
    private function fallback(mixed $value, array $paths, string $path, MarkdownContext $ctx): string
    {
        if (is_string($value)) {
            if (! isset($paths[$path])) {
                return '';
            }

            return RichtextState::isHtml($value) ? $ctx->richtext($value) : $ctx->text($value);
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $key => $child) {
            $childPath = array_is_list($value) ? $path : ($path === '' ? (string) $key : $path . '.' . $key);
            $part = $this->fallback($child, $paths, $childPath, $ctx);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode("\n\n", $parts);
    }
}
