<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Facades\Cms;
use Tbtop\Cms\Page\Types\CategoryType;

/**
 * The recipes.md tag: a category subtype under a "Tags" category, kept off
 * the Pages "All" tab, an archive of the `tags` field that lists two
 * tagged articles per page.
 */
class TagPageType extends CategoryType
{
    #[\Override]
    public function key(): string
    {
        return 'tag';
    }

    #[\Override]
    public function base(): string
    {
        return 'category';
    }

    #[\Override]
    public function listedInAll(): bool
    {
        return false;
    }

    #[\Override]
    public function archiveOf(): ?string
    {
        return 'tags';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    #[\Override]
    public function resolveFields(array $input, ResolveContext $ctx): array
    {
        $articles = Cms::referencedByLinksPaged($ctx->page, 'tags', from: 'article', perPage: 2);

        return [...$articles, 'siblings' => Cms::childrenLinksPaged($ctx->page?->parent, perPage: 10)];
    }
}
