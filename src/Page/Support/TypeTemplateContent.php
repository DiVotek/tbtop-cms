<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Illuminate\Support\Str;
use Tbtop\Cms\Block\BlockEnvelope;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Seeds a brand-new page's `content` from its type's template() at create
 * time — PageFormWriter::fillContent() calls this when the page inherited no starter
 * blocks. Without it the create wrote `[]`, and PageController only renders
 * template() for a null `content`, so the default blocks never showed on an
 * admin-made page.
 *
 * The template is read off the base type (`$page->type`), the same one
 * PageController renders it from. Each block gets a fresh id, so two pages
 * (or two blocks of one template) never share one, and defaults for what a
 * template may leave out: active=true, its position as sort, and empty data.
 * Any other key the template sets is kept.
 */
final class TypeTemplateContent
{
    public function __construct(private readonly PageTypeRegistry $types) {}

    /** @return list<array<string, mixed>> */
    public function contentFor(Page $newPage): array
    {
        if (! $this->types->has($newPage->type)) {
            return [];
        }

        $template = BlockEnvelope::blocksOf($this->types->get($newPage->type)->template($newPage));

        return array_map(
            static fn (array $block, int $index): array => [
                'active' => true,
                'sort' => $index,
                'data' => [],
                ...$block,
                'id' => (string) Str::ulid(),
            ],
            $template,
            array_keys($template),
        );
    }
}
