<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Resolves which SchemaTypeInterface key a page publishes: the stored
 * seo.schema.type if it is one of the page's schemaTypes (its subtype's, else
 * its type's — PageTypeRegistry::schemaTypesFor()),
 * otherwise the type's first (default) key. Falls back to
 * SchemaTypeRegistry::FALLBACK_KEY when the page's type is no longer
 * registered in PageTypeRegistry, or (a host contract violation —
 * schemaTypes() is typed non-empty) when it returns no keys at all.
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
            return $this->schemaTypes->get(SchemaTypeRegistry::FALLBACK_KEY);
        }

        return $this->schemaTypes->get($this->selectedKey($page, $this->pageTypes->schemaTypesFor($page)));
    }

    /** @param  list<string>  $available */
    private function selectedKey(Page $page, array $available): string
    {

        $seo = is_array($page->seo) ? $page->seo : [];
        $schema = is_array($seo['schema'] ?? null) ? $seo['schema'] : [];
        $stored = $schema['type'] ?? null;

        if (is_string($stored) && in_array($stored, $available, true)) {
            return $stored;
        }

        return self::defaultKey($available);
    }

    /**
     * schemaTypes() is declared non-empty-list<string>, but that is a
     * PHPDoc promise a host implementation can still break at runtime — this
     * takes the array through an untyped boundary so an empty return still
     * degrades to the registry fallback instead of an undefined-index
     * warning.
     *
     * @param  list<string>  $keys
     */
    private static function defaultKey(array $keys): string
    {
        return array_key_exists(0, $keys) ? $keys[0] : SchemaTypeRegistry::FALLBACK_KEY;
    }
}
