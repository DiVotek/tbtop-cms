<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Types\BasePageType;

/**
 * Source-backed page type fixture (S3 acceptance tests): source() loaded by
 * source_id — 'missing' simulates a deleted source, resolving to null so
 * PageController's 404 branch is exercised. template() returns one Hero
 * block, standing in for a real storefront kind template.
 */
class SourcedType extends BasePageType
{
    public function key(): string
    {
        return 'sourced';
    }

    #[\Override]
    public function isSourceBacked(): bool
    {
        return true;
    }

    #[\Override]
    public function source(Page $page): ?object
    {
        if ($page->source_id === null || $page->source_id === 'missing') {
            return null;
        }

        return new SourcedThing($page->source_id);
    }

    /** @return array<int, array<string, mixed>>|null */
    #[\Override]
    public function template(Page $page): ?array
    {
        return [
            ['id' => 'tpl-hero', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Template Hero']]],
        ];
    }
}
