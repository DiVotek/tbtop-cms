<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Resolves which SchemaTypeInterface key a page publishes: the stored
 * seo.schema.type if it is one of the page type's own schemaTypes(),
 * otherwise the type's first (default) key. A page whose type is no longer
 * registered in PageTypeRegistry falls back to a plain WebPage.
 */
final class SchemaSelector
{
    public function __construct(
        private readonly PageTypeRegistry $pageTypes,
        private readonly SchemaTypeRegistry $schemaTypes,
    ) {}

    public function selectFor(Page $page): SchemaTypeInterface
    {
        if (! $this->pageTypes->has($page->type)) {
            return $this->schemaTypes->get('WebPage');
        }

        $pageType = $this->pageTypes->get($page->type);
        $key = $this->selectedKey($page, $pageType);

        return $this->schemaTypes->get($key);
    }

    private function selectedKey(Page $page, PageTypeInterface $pageType): string
    {
        $available = $pageType->schemaTypes();
        $seo = is_array($page->seo) ? $page->seo : [];
        $schema = is_array($seo['schema'] ?? null) ? $seo['schema'] : [];
        $stored = $schema['type'] ?? null;

        return is_string($stored) && in_array($stored, $available, true) ? $stored : $available[0];
    }
}
