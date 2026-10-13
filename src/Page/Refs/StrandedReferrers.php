<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Models\Page;

/**
 * The pages a retype of $target would strand: each references $target through
 * a pages field whose of() accepts it as stored but not as the new
 * type/subtype. page_ref still lets them save and the site keeps listing the
 * target; only the field's search stops offering it. Trashed referrers are
 * left out, drafts are not.
 */
final class StrandedReferrers
{
    public function __construct(private readonly PageRefIndex $index) {}

    /**
     * One lookup for every candidate subtype of $target's own type; a key is
     * a subtype, '' for none. A candidate that strands nobody is left out.
     *
     * @param  list<string>  $subtypes
     * @return array<string, list<Page>>
     */
    public function bySubtype(Page $target, array $subtypes): array
    {
        $type = (string) $target->type;
        $referrers = $this->referrers($target);
        $out = [];

        foreach ($subtypes as $subtype) {
            $stranded = [];
            foreach ($referrers as $ref) {
                if (! $ref['filter']->accepts($type, $subtype === '' ? null : $subtype)) {
                    $stranded[$ref['page']->getKey()] = $ref['page'];
                }
            }
            if ($stranded !== []) {
                $out[$subtype] = array_values($stranded);
            }
        }

        return $out;
    }

    /**
     * Referencing fields that accept $target as it is stored.
     *
     * @return list<array{page: Page, filter: PageTypeFilter}>
     */
    private function referrers(Page $target): array
    {
        $rows = DB::table(PageRefIndex::TABLE)->where('target_id', $target->getKey())->get(['page_id', 'field']);
        if ($rows->isEmpty()) {
            return [];
        }

        // Only what the match and the confirm's names need: a widely referenced page can have many referrers.
        $pages = Page::query()->whereKey($rows->pluck('page_id')->unique()->all())
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'type', 'subtype', 'name', 'sort_order']);
        $type = (string) $target->type;
        $subtype = $target->subtype === null ? null : (string) $target->subtype;
        $out = [];
        $fieldsByType = [];

        foreach ($pages as $page) {
            // Declarations depend on the type/subtype pair alone, so referrers of one kind share them.
            $fields = $fieldsByType[$page->type . '/' . $page->subtype] ??= $this->index->pagesFields($page);
            foreach ($rows->where('page_id', $page->getKey())->pluck('field')->unique() as $name) {
                $filter = isset($fields[$name]) ? $fields[$name]->typeFilter() : null;
                if ($filter !== null && $filter->accepts($type, $subtype)) {
                    $out[] = ['page' => $page, 'filter' => $filter];
                }
            }
        }

        return $out;
    }
}
