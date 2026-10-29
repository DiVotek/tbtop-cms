<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Collections;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TableBuilder;
use Tbtop\Cms\Admin\Pages\CollectionEntriesPage;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Page\Models\Page;

/**
 * A collection's entries as an admin table: name, status, last change; drag
 * to reorder, click to edit, delete per row. The Collections page shows it
 * whole, in the collection's tab; any admin page can embed it narrowed to
 * one page's related entries — a service editor listing its reviews:
 *
 *     CollectionTable::make($s, 'review')->relatedTo($page)->build()
 *
 * collections.md.
 */
final class CollectionTable
{
    private ?int $pageId = null;

    private function __construct(
        private readonly S $s,
        private readonly string $key,
    ) {}

    public static function make(S $s, string $key): self
    {
        app(CollectionRegistry::class)->get($key);

        return new self($s, $key);
    }

    /** Only the entries whose related `pages` field (Collection::relatedField()) picks this page. */
    public function relatedTo(Page | int $page): self
    {
        $this->pageId = $page instanceof Page ? (int) $page->getKey() : $page;

        return $this;
    }

    public function build(): TableBuilder
    {
        $s = $this->s;
        $key = $this->key;
        $name = 'entries_' . str_replace('-', '_', $key) . ($this->pageId === null ? '' : '_related');

        $table = $s->table($name)
            ->query(fn (): Builder => $this->query())
            ->columns([
                Column::make('title')->label(__('tbtop-cms::collections.columns.title'))->emphasized()
                    ->formatUsing(static fn (mixed $value, object $row): string => is_string($value) && $value !== '' ? $value : '#' . ($row->id ?? '')),
                Column::make('status')->label(__('tbtop-cms::collections.columns.status'))->badge([
                    Entry::PUBLISHED => ['label' => __('tbtop-cms::collections.status.published'), 'color' => Color::Success],
                    Entry::DRAFT => ['label' => __('tbtop-cms::collections.status.draft'), 'color' => Color::Gray],
                ]),
                Column::make('updated_at')->label(__('tbtop-cms::collections.columns.updated_at'))->datetime(),
            ])
            ->searchable(['title'])
            ->reorderable('sort_order')
            ->recordUrl(static fn (object $row): string => self::editUrl($key, (int) ($row->id ?? 0)))
            ->rowActions([self::deleteAction($s, $name, fn (): Builder => $this->query())])
            ->emptyState(__('tbtop-cms::collections.empty.title'), __('tbtop-cms::collections.empty.description'));

        return $this->pageId === null ? $table : $table->embedded();
    }

    /** The Collections page with this collection's tab open. */
    public static function listUrl(string $key): string
    {
        return route('tbtop.cms.collection-entries-page', ['tab' => [CollectionEntriesPage::TABS => $key]]);
    }

    public static function editUrl(string $key, int $id): string
    {
        return route('tbtop.cms.collection-entry-edit-page', ['collection' => $key, 'entry' => $id]);
    }

    /** @return Builder<Entry> */
    private function query(): Builder
    {
        $query = Entry::query()->of($this->key);
        $field = app(CollectionRegistry::class)->get($this->key)->relatedField();

        if ($this->pageId !== null) {
            // A `pages` field stores ids as strings; no relation field means nothing is related.
            $field === null
                ? $query->whereRaw('1 = 0')
                : $query->whereJsonContains('data->' . $field, (string) $this->pageId);
        }

        return $query;
    }

    /**
     * Gated like the collection's own page, so a host page that embeds the
     * table under a looser gate cannot delete through it.
     *
     * @param  Closure(): Builder<Entry>  $query  the table's own scope, so a row it does not show cannot be deleted through it
     */
    private static function deleteAction(S $s, string $table, Closure $query): ActionBuilder
    {
        $action = $s->action($table . 'Delete');
        $gate = CollectionEntriesPage::can();
        if ($gate !== null) {
            $action->authorize($gate);
        }

        return $action->label('')->icon('trash')->color('danger')
            ->tooltip(__('tbtop-cms::collections.actions.delete'))
            ->confirm(__('tbtop-cms::collections.actions.delete_title'), __('tbtop-cms::collections.actions.delete_confirm'))
            ->handle(static function (ActionCtx $ctx) use ($table, $query): Effects {
                $entry = $query()->find($ctx->key());
                $entry?->delete();

                return Effects::make()->notify(__('tbtop-cms::collections.actions.deleted'))->refreshTable($table);
            }, needs: ['row']);
    }
}
