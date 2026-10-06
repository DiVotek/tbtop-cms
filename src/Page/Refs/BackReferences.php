<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * PageTypeInterface::backReferences() resolved for one page: base type's
 * declarations, then the subtype's over them, each a list of the live pages
 * that reference this one through the declared field.
 */
final class BackReferences
{
    public function __construct(
        private readonly PageTypeRegistry $types,
        private readonly PageRefIndex $index,
        private readonly PageRefList $list,
    ) {}

    /** @return array<string, list<array{id: string, name: string, url: string}>> */
    public function resolve(Page $page, string $locale): array
    {
        $declared = [];
        foreach ($this->types->forPage($page) as $type) {
            $declared = [...$declared, ...$type->backReferences()];
        }

        $out = [];
        foreach ($declared as $key => $spec) {
            $from = $spec['from'] ?? null;
            $out[$key] = $this->list->items($this->index->sources($page, $spec['field'], $from, $locale), $locale);
        }

        return $out;
    }
}
