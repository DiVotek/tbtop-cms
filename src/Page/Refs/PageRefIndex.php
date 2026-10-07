<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Fields\Pages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * `tbtop_page_refs`: one row per id a page's type field of kind pages holds,
 * so "which pages reference me through field X" is an indexed lookup instead
 * of a scan of every page's `fields` JSON. Only page-type fields are indexed,
 * never block data. Trashed sources keep their rows — a restore brings the
 * references back, and readers filter on live() anyway.
 */
final class PageRefIndex
{
    public const TABLE = 'tbtop_page_refs';

    public function __construct(private readonly PageTypeRegistry $types) {}

    public function write(Page $page): void
    {
        $rows = $this->rowsFor($page);

        DB::transaction(static function () use ($page, $rows): void {
            DB::table(self::TABLE)->where('page_id', $page->getKey())->delete();
            if ($rows !== []) {
                DB::table(self::TABLE)->insert($rows);
            }
        });
    }

    /** @return int rows written */
    public function rebuild(): int
    {
        $count = 0;

        DB::transaction(function () use (&$count): void {
            DB::table(self::TABLE)->delete();

            Page::withTrashed()->orderBy('id')->chunkById(200, function (Collection $pages) use (&$count): void {
                $rows = [];
                foreach ($pages as $page) {
                    $rows = [...$rows, ...$this->rowsFor($page)];
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table(self::TABLE)->insert($chunk);
                }
                $count += count($rows);
            });
        });

        return $count;
    }

    /**
     * Live pages whose field $field references $target, optionally only
     * those whose type or subtype is $from; by sort_order, then name.
     *
     * @return list<Page>
     */
    public function sources(Page $target, string $field, ?string $from, string $locale): array
    {
        $query = Page::query()->live()->with('slugs')
            ->whereIn('id', DB::table(self::TABLE)->select('page_id')
                ->where('target_id', $target->getKey())
                ->where('field', $field));

        if ($from !== null) {
            $query->where(static fn (Builder $q): Builder => $q->where('type', $from)->orWhere('subtype', $from));
        }

        return $query->get()
            ->sortBy([
                static fn (Page $a, Page $b): int => $a->sort_order <=> $b->sort_order,
                static fn (Page $a, Page $b): int => strnatcasecmp((string) PageRefList::nameOf($a, $locale), (string) PageRefList::nameOf($b, $locale)),
                static fn (Page $a, Page $b): int => $a->id <=> $b->id,
            ])
            ->values()
            ->all();
    }

    /** @return list<array{page_id: int, field: string, target_id: int, position: int}> */
    private function rowsFor(Page $page): array
    {
        $stored = is_array($page->fields) ? $page->fields : [];
        $rows = [];

        foreach ($this->pagesFieldNames($page) as $name) {
            foreach (PageIds::list($stored[$name] ?? null) as $position => $target) {
                // A page never lists itself among its own back references, whatever path wrote the id.
                if ($target === (int) $page->getKey()) {
                    continue;
                }
                $rows[] = ['page_id' => (int) $page->getKey(), 'field' => $name, 'target_id' => $target, 'position' => $position];
            }
        }

        return $rows;
    }

    /**
     * A name the subtype shares with its base is indexed by the subtype's
     * declaration of it — the later one wins, as in page.fields.
     *
     * @return list<string>
     */
    private function pagesFieldNames(Page $page): array
    {
        $declared = [];
        foreach ($this->types->forPage($page) as $type) {
            foreach (S::normalizeChildren($type->fields(new S)) as $field) {
                if ($field instanceof Field) {
                    $declared[$field->name] = $field;
                }
            }
        }

        return array_keys(array_filter($declared, static fn (Field $field): bool => $field instanceof Pages));
    }
}
