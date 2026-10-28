<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Enums\PageStatus;
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

    /** Index rows of live pages picking $targetId (any target when null) through $field. */
    private function referrers(string $field, ?int $targetId, ?QueryBuilder $query = null): QueryBuilder
    {
        $now = Carbon::now();
        $pages = (new Page)->getTable();

        $query = ($query ?? DB::query())->select('r.page_id')
            ->from(PageRefIndex::TABLE, 'r')
            ->join("{$pages} as s", 's.id', '=', 'r.page_id')
            ->where('r.field', $field)
            ->whereNull('s.deleted_at')
            ->where('s.status', PageStatus::Published->value)
            ->where(static fn (QueryBuilder $q): QueryBuilder => $q->whereNull('s.published_at')->orWhere('s.published_at', '<=', $now))
            ->where(static fn (QueryBuilder $q): QueryBuilder => $q->whereNull('s.unpublish_at')->orWhere('s.unpublish_at', '>', $now));

        return $targetId === null ? $query : $query->where('r.target_id', $targetId);
    }
}
