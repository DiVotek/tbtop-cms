<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Actions\ReplicateAction;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Tab;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Admin\Pages\Support\PageSortKey;
use Tbtop\Cms\Admin\Support\AuthorNames;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EnumLabel;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageDisplayState;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Events\PageRestored;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\Support\PageDuplicator;

class PagesPage extends AdminPage
{
    public static function path(): string
    {
        return 'pages';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'content',
            'label' => __('tbtop-cms::nav.items.pages'),
            'order' => 10,
            'icon' => 'files',
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.pages');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::pages.title');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.content')],
            ['label' => __('tbtop-cms::nav.items.pages')],
        ];
    }

    /** @return list<ActionBuilder|Node> */
    #[\Override]
    public function headerActions(S $s): array
    {
        return [$this->createAction($s)];
    }

    public function view(S $s): Node
    {
        $pages = (new Page)->getTable();

        $table = $s->table('pages')
            ->query($this->query(...))
            ->columns([
                Column::make('name')->label(__('tbtop-cms::pages.columns.title'))->translatable()->emphasized(),
                Column::make('type')->label(__('tbtop-cms::pages.columns.type'))
                    ->formatUsing($this->typeLabel(...))
                    ->muted()->uppercase(),
                Column::make('parent_name')->label(__('tbtop-cms::pages.columns.parent'))->translatable()->muted(),
                // Bound to the display_state computed column (see query()),
                // not the raw status column — mirrors PageEditStatusBar's
                // badge, which shows the same computed scheduled/expired
                // states. Colors follow the same "no info/muted token"
                // fallback documented on PageEditStatusBar::STATUS_COLORS.
                Column::make('display_state')->label(__('tbtop-cms::pages.columns.status'))
                    ->formatUsing($this->statusLabel(...))
                    ->badge([
                        __('tbtop-cms::pages.status.published') => 'success',
                        __('tbtop-cms::pages.status.draft') => 'warning',
                        __('tbtop-cms::pages.status.scheduled') => 'gray',
                        __('tbtop-cms::pages.status.expired') => 'gray',
                    ]),
                Column::make('seo_state')->label(__('tbtop-cms::pages.columns.seo'))
                    ->formatUsing($this->seoStateLabel(...))
                    ->badge([
                        __('tbtop-cms::pages.seo_state.fail') => 'danger',
                        __('tbtop-cms::pages.seo_state.warn') => 'warning',
                        __('tbtop-cms::pages.seo_state.pass') => 'success',
                    ])
                    ->toggleable(),
                Column::make('updated_at')->label(__('tbtop-cms::pages.columns.updated'))
                    ->date(__('tbtop-cms::pages.date_format'))->muted(),
                // Off by default — the compact status bar / revisions panel
                // already surface authorship on the edit page; this column
                // is here for the rare "who touched this" table-level sweep.
                Column::make('updated_by_name')->label(__('tbtop-cms::pages.columns.updated_by'))
                    ->toggleable(true, hiddenByDefault: true)->muted(),
                // Not shown by default — projected purely so hiddenIf() can
                // distinguish trashed rows client-side (empty vs non-empty).
                Column::make('deleted_at')->label(__('tbtop-cms::pages.columns.deleted_at'))
                    ->date(__('tbtop-cms::pages.date_format'))->toggleable(true, hiddenByDefault: true),
            ])
            ->tabs([
                $this->tab('all'),
                $this->tab('categories')
                    ->query(static fn (Builder $q): Builder => $q->where("{$pages}.type", 'category')),
                $this->tab('pages')
                    ->query(static fn (Builder $q): Builder => $q->where("{$pages}.type", '!=', 'category')->whereNull("{$pages}.parent_id")),
                $this->tab('articles')
                    ->query(static fn (Builder $q): Builder => $q->where("{$pages}.type", '!=', 'category')->whereNotNull("{$pages}.parent_id")),
                Tab::make('deleted')->label(__('tbtop-cms::pages.tabs.deleted'))->count()
                    ->description(__('tbtop-cms::pages.tabs_deleted_description'))
                    ->query($this->onlyTrashedScope(...)),
            ])
            ->searchable(["{$pages}.name"])
            ->defaultSort('sort_key', 'asc')
            ->emptyState(
                __('tbtop-cms::pages.empty.title'),
                __('tbtop-cms::pages.empty.description'),
                'files',
            )
            // null for a trashed row: no edit page for a soft-deleted record,
            // so the row falls back to non-clickable (see tableRow.tsx —
            // recordUrl is only honored when _recordUrl is a string).
            ->recordUrl(static fn (Page $row): ?string => $row->trashed()
                ? null
                : route('tbtop.cms.page-edit-page', ['page' => $row->id]))
            ->rowActions([
                $this->viewOnSiteAction($s),
                $this->duplicateAction($s),
                $this->restoreAction($s),
                $this->forceDeleteAction($s),
            ]);

        return $s->stack([$table]);
    }

    /** @return Builder<Page> */
    private function query(): Builder
    {
        $default = app(Languages::class)->defaultLocale();
        $table = (new Page)->getTable();

        $query = Page::query()->select("{$table}.*")
            ->selectSub(
                PageSlug::query()->select('path')
                    ->whereColumn('page_id', "{$table}.id")
                    ->where('locale', $default)
                    ->limit(1),
                'path',
            )
            ->selectSub(
                DB::table($table, 'parents')->select('parents.name')
                    ->whereColumn('parents.id', "{$table}.parent_id")
                    ->whereNull('parents.deleted_at')
                    ->limit(1),
                'parent_name',
            )
            ->selectSub(AuthorNames::subselect($table, 'updated_by'), 'updated_by_name')
            ->selectRaw($this->displayStateSql($table), $this->displayStateBindings())
            ->selectRaw($this->seoStateSql($table));

        return PageSortKey::apply($query);
    }

    /**
     * SQL mirror of Page::displayState() (that method stays the source of
     * truth for the model-level API — see its docblock) so the status column
     * can badge scheduled/expired without an N+1 model hydration per row.
     * Plain ANSI CASE/COALESCE, no driver-specific function, same portability
     * approach as AuthorNames::subselect() — reads identically on sqlite
     * (tests), mysql, and postgres (hosts).
     *
     * Columns are table-qualified: PageSortKey::apply() below self-joins
     * tbtop_pages up to 4 levels deep as sort_key_p1..p4, so an unqualified
     * `status`/`published_at`/`unpublish_at` is ambiguous once those joins
     * are in the same query.
     */
    private function displayStateSql(string $table): string
    {
        return <<<SQL
            CASE
                WHEN {$table}.status != ? THEN ?
                WHEN {$table}.published_at IS NOT NULL AND {$table}.published_at > ? THEN ?
                WHEN {$table}.unpublish_at IS NOT NULL AND {$table}.unpublish_at <= ? THEN ?
                ELSE ?
            END as display_state
            SQL;
    }

    /** @return list<string> */
    private function displayStateBindings(): array
    {
        $now = now()->toDateTimeString();

        return [
            PageStatus::Published->value, PageDisplayState::Draft->value,
            $now, PageDisplayState::Scheduled->value,
            $now, PageDisplayState::Expired->value,
            PageDisplayState::Published->value,
        ];
    }

    /**
     * `name`/`title`/`summary`/`seo` are all translatable JSON-map columns
     * (`{"en": "...", "uk": "..."}`, see Page::$translatable and
     * LocalizedSeo's docblock) — checking whether a given LOCALE's value
     * inside that JSON is empty needs per-driver JSON extraction:
     * `JSON_EXTRACT`/`->>'$.en'` on MySQL, `->>'en'` on Postgres, a third
     * shape on sqlite. Three divergent syntaxes for one column is exactly
     * the "ugly or unreliable" case this column is meant to avoid (see the
     * class docblock precedent: AuthorNames::subselect() and
     * displayStateSql() above both stay on plain ANSI SQL for the same
     * reason), so this narrows to what a plain CASE can express reliably
     * everywhere: `is_indexable` (a real boolean column) plus whether
     * `name`/`title`/`summary` hold any content at all, not whether the
     * resolved value for one particular locale is empty. A page with content
     * only in a non-default locale reads as "has a title source" here even
     * if the default locale's slice is blank — a false pass, not a false
     * fail, which is the safer direction for a list-page hint whose
     * authoritative detail lives in the per-page SEO checklist
     * (SeoChecklist::for()).
     */
    private function seoStateSql(string $table): string
    {
        // An unset translatable column is not NULL — the cast writes `[]` for
        // an empty map and `{"en":""}` for a blank entry, so emptiness has to
        // be tested against those literals rather than IS NULL. Both are short
        // fixed strings, which plain ANSI comparison handles on every driver.
        $emptyTitle = "COALESCE({$table}.title, '[]') IN ('[]', '{}', '\"\"')";
        $emptyName = "COALESCE({$table}.name, '[]') IN ('[]', '{}', '\"\"')";
        $emptySummary = "COALESCE({$table}.summary, '[]') IN ('[]', '{}', '\"\"')";

        return <<<SQL
            CASE
                WHEN {$emptyName} AND {$emptyTitle} THEN 'fail'
                WHEN {$emptySummary} OR {$table}.is_indexable = 0 THEN 'warn'
                ELSE 'pass'
            END as seo_state
            SQL;
    }

    /**
     * @param  Builder<Page>  $q
     * @return Builder<Page>
     */
    private function onlyTrashedScope(Builder $q): Builder
    {
        return $q->onlyTrashed();
    }

    private function createAction(S $s): ActionBuilder
    {
        $cancel = $s->action('pageCreateCancel')->label(__('tbtop-cms::common.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $create = $s->action('pageCreateSave')->label(__('tbtop-cms::pages.actions.create'))->color('primary')
            ->handle($this->create(...), needs: ['form']);

        $form = $s->form('pageCreate', [
            ...PageForm::fields($s),
            $s->flex([$s->spacer(), $cancel, $create], justify: 'end'),
        ]);

        return $s->action('pageCreate')->label(__('tbtop-cms::pages.actions.new'))->icon('plus')->color('primary')
            ->modal(__('tbtop-cms::pages.actions.create_title'), $form);
    }

    private function viewOnSiteAction(S $s): ActionBuilder
    {
        // label('') → icon-only; the tooltip carries the accessible name.
        return $s->action('pageView')->label('')->link()->icon('external-link')
            ->tooltip(__('tbtop-cms::pages.actions.view_on_site'))
            ->visit('/{path}', newTab: true)
            // A trashed page has no live path (its slugs were removed by
            // PageObserver::deleting()) — the link would 404.
            ->hiddenIf('deleted_at', 'not empty');
    }

    private function duplicateAction(S $s): ActionBuilder
    {
        return ReplicateAction::make($s, $this->duplicate(...), name: 'pageDuplicate')
            ->label(__('tbtop-cms::pages.actions.duplicate'))
            // Only active rows can be duplicated — a trashed page has no live
            // slug/children guarantees to clone from meaningfully, and
            // restore/forceDelete already own the trashed-row action slot.
            ->hiddenIf('deleted_at', 'not empty');
    }

    private function duplicate(ActionCtx $ctx): Effects
    {
        $page = Page::findOrFail((int) ($ctx->row['id'] ?? 0));

        app(PageDuplicator::class)->duplicate($page);

        return Effects::make()->notify(__('tbtop-cms::pages.duplicate.notify'))->refreshTable();
    }

    private function restoreAction(S $s): ActionBuilder
    {
        return $s->action('pageRestore')->label(__('tbtop-cms::pages.actions.restore'))->color('gray')
            ->handle($this->restore(...), needs: ['row'])
            ->hiddenIf('deleted_at', 'empty');
    }

    private function restore(ActionCtx $ctx): Effects
    {
        $page = Page::withTrashed()->findOrFail((int) ($ctx->row['id'] ?? 0));

        try {
            $page->restore();
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        event(new PageRestored($page));

        return Effects::make()->notify(__('tbtop-cms::pages.actions.restored'))->refreshTable();
    }

    private function forceDeleteAction(S $s): ActionBuilder
    {
        return $s->action('pageForceDelete')->label(__('tbtop-cms::pages.actions.force_delete'))->color('danger')
            ->confirm(__('tbtop-cms::pages.actions.force_delete_title'), __('tbtop-cms::pages.actions.force_delete_confirm'))
            ->handle($this->forceDelete(...), needs: ['row'])
            ->hiddenIf('deleted_at', 'empty');
    }

    private function forceDelete(ActionCtx $ctx): Effects
    {
        $page = Page::withTrashed()->findOrFail((int) ($ctx->row['id'] ?? 0));

        try {
            $page->forceDelete();
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        event(new PageDeleted($page, forced: true));

        return Effects::make()->notify(__('tbtop-cms::pages.actions.force_deleted'))->refreshTable();
    }

    private function tab(string $name): Tab
    {
        $label = __('tbtop-cms::pages.tabs.' . $name);

        return Tab::make($name)->label($label)->count()
            ->description(__('tbtop-cms::pages.tab_description', ['tab' => $label]));
    }

    private function typeLabel(mixed $value): string
    {
        return EnumLabel::make('tbtop-cms::pages.types', $value);
    }

    private function statusLabel(mixed $value): string
    {
        return EnumLabel::make('tbtop-cms::pages.status', $value);
    }

    private function seoStateLabel(mixed $value): string
    {
        return EnumLabel::make('tbtop-cms::pages.seo_state', $value);
    }

    private function create(ActionCtx $ctx): Effects
    {
        $page = PageForm::save($ctx->form);

        return Effects::make()->notify(__('tbtop-cms::pages.actions.created'))->closeModal()->redirect(
            route('tbtop.cms.page-edit-page', ['page' => $page->id]),
        );
    }
}
