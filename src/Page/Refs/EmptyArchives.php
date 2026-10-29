<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * An archive page (its type names PageTypeInterface::archiveOf()) that no
 * live page picks through that field: a tag nothing is tagged with. Search
 * engines get noindex for it and the sitemap leaves it out, so empty tag
 * pages do not dilute the site.
 */
final readonly class EmptyArchives
{
    public function __construct(private PageTypeRegistry $types) {}

    public function isEmpty(Page $page): bool
    {
        $fields = $this->types->archiveFields();
        $field = ($page->subtype !== null ? $fields[$page->subtype] ?? null : null) ?? $fields[$page->type] ?? null;

        if ($field === null) {
            return false;
        }

        return ! DB::query()->fromSub($this->referrers($field, (int) $page->getKey()), 'refs')->exists();
    }

    /**
     * Leaves empty archives out of a pages query.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function exclude(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        foreach ($this->types->archiveFields() as $type => $field) {
            $query->where(fn (Builder $q): Builder => $q
                ->where(static fn (Builder $other): Builder => $other->where("{$table}.type", '!=', $type)
                    ->where(static fn (Builder $sub): Builder => $sub->whereNull("{$table}.subtype")->orWhere("{$table}.subtype", '!=', $type)))
                ->orWhereExists(fn (QueryBuilder $refs): QueryBuilder => $this->referrers($field, null, $refs)->whereColumn('r.target_id', "{$table}.id")));
        }

        return $query;
    }

    /** Index rows of live pages (Page::live(), the listings' own rule) picking $targetId (any target when null) through $field. */
    private function referrers(string $field, ?int $targetId, ?QueryBuilder $query = null): QueryBuilder
    {
        $query = ($query ?? DB::query())->select('r.page_id')
            ->from(PageRefIndex::TABLE, 'r')
            ->where('r.field', $field)
            ->whereIn('r.page_id', Page::query()->live()->select('id')->toBase());

        return $targetId === null ? $query : $query->where('r.target_id', $targetId);
    }
}
