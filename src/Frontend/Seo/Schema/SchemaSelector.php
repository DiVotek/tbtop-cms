<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Resolves which SchemaTypeInterface key a page publishes: the stored
 * seo.schema.type if it is one of the page's schemaTypes (its subtype's, else
 * its type's — PageTypeRegistry::schemaTypesFor()) that SchemaTypeRegistry
 * knows, otherwise the first known (default) key. Falls back to
 * SchemaTypeRegistry::FALLBACK_KEY when the page's type is no longer
 * registered in PageTypeRegistry, or when none of its keys is known (an
 * empty list breaks the non-empty schemaTypes() contract; an unknown key is
 * a host typo or a missing schema class).
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

        return $this->schemaTypes->get($this->selectedKey($page, $this->availableKeys($page)));
    }

    /**
     * The page's schemaTypes() keys that SchemaTypeRegistry knows. A host
     * type may list a key no schema type registers (a typo, a schema class
     * not yet added); it is skipped here — and named by the
     * unknown_schema_types doctor check — rather than failing every render.
     *
     * @return list<string>
     */
    public function availableKeys(Page $page): array
    {
        return array_values(array_filter(
            $this->pageTypes->schemaTypesFor($page),
            fn (string $key): bool => $this->schemaTypes->has($key),
        ));
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
