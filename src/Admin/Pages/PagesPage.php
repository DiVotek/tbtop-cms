<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Tab;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Admin\Pages\Support\PageFormOptions;
use Tbtop\Cms\Admin\Pages\Support\PageListState;
use Tbtop\Cms\Admin\Pages\Support\PageSortKey;
use Tbtop\Cms\Admin\Pages\Support\PagesRowActions;
use Tbtop\Cms\Admin\Support\AuthorNames;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EnumLabel;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageDisplayState;
use Tbtop\Cms\Page\Enums\SeoState;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\PageTypeRegistry;

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
        $subtypes = $this->subtypeOptions();

        $table = $s->table('pages')
            ->query($this->query(...))
            ->columns($this->columns($subtypes))
            ->tabs($this->tabs($pages))
            ->filters($this->filters($s, $pages))
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
            ->rowActions(PagesRowActions::all($s));

        return $s->stack([$table]);
    }

    /**
     * @param  list<array{value: string, label: string}>  $subtypes
     * @return list<Column>
     */
    private function columns(array $subtypes): array
    {
        return [
            Column::make('name')->label(__('tbtop-cms::pages.columns.title'))->translatable()->emphasized(),
            Column::make('type')->label(__('tbtop-cms::pages.columns.type'))
                ->formatUsing($this->typeLabel(...))
                ->muted()->uppercase(),
            // Only once a host registers a subtype: before that every row would be blank.
            ...($subtypes === [] ? [] : [
                Column::make('subtype')->label(__('tbtop-cms::pages.columns.subtype'))
                    ->formatUsing($this->typeLabel(...))
                    ->muted()->uppercase(),
            ]),
            Column::make('parent_name')->label(__('tbtop-cms::pages.columns.parent'))->translatable()->muted(),
            // Bound to the display_state computed column (see query()),
            // not the raw status column — mirrors PageEditStatusBar's
            // badge, which shows the same computed scheduled/expired
            // states. Colors follow the same "no info/muted token"
            // fallback documented on PageEditStatusBar::STATUS_COLORS.
            Column::make('display_state')->label(__('tbtop-cms::pages.columns.status'))
                ->formatUsing($this->statusLabel(...))
                ->badge([
                    __('tbtop-cms::pages.status.published') => ['color' => 'success'],
                    __('tbtop-cms::pages.status.draft') => ['color' => 'warning'],
                    __('tbtop-cms::pages.status.scheduled') => ['color' => 'gray'],
                    __('tbtop-cms::pages.status.expired') => ['color' => 'gray'],
                ]),
            // Sortable ascending = worst first, which is the sweep this
            // badge exists for ("show me what needs work"). That ordering
            // is why the query selects SeoState's rank rather than its
            // name — see PageListState::seoStateSql().
            Column::make('seo_state')->label(__('tbtop-cms::pages.columns.seo'))
                ->formatUsing($this->seoStateLabel(...))
                ->tooltip(PageListState::seoTooltip(...))
                ->sortable()
                ->badge([
                    __('tbtop-cms::pages.seo_state.fail') => ['color' => 'danger'],
                    __('tbtop-cms::pages.seo_state.warn') => ['color' => 'warning'],
                    __('tbtop-cms::pages.seo_state.pass') => ['color' => 'success'],
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
            // Not shown by default — projected purely for the view-on-site
            // link's '/{path}' template: a row carries only declared columns.
            Column::make('path')->label(__('tbtop-cms::pages.columns.path'))
                ->toggleable(true, hiddenByDefault: true)->muted(),
            // Not shown by default — projected purely so the duplicate row
            // action can hide itself for source-backed rows. Only declared
            // when such a type is registered (query() selects it then too).
            ...(self::sourceBackedTypes() === [] ? [] : [
                Column::make('source_backed')->label(__('tbtop-cms::pages.columns.source_backed'))
                    ->boolean()->toggleable(true, hiddenByDefault: true),
            ]),
        ];
    }

    /** @return list<Tab> */
    private function tabs(string $pages): array
    {
        return [
            $this->allTab($pages),
            ...$this->typeTabs($pages),
            Tab::make('deleted')->label(__('tbtop-cms::pages.tabs.deleted'))->count()
                ->description(__('tbtop-cms::pages.tabs_deleted_description'))
                ->query($this->onlyTrashedScope(...)),
        ];
    }

    /** @return list<Field> */
    private function filters(S $s, string $pages): array
    {
        return [
            // Qualified: PageSortKey self-joins the pages table, so a bare `parent_id` is ambiguous.
            $s->select('parent_id')->label(__('tbtop-cms::pages.columns.parent'))->options($this->parentOptions())
                ->filterUsing(static fn (Builder $q, mixed $value): Builder => $q->where("{$pages}.parent_id", $value)),
            $s->select('display_state')->label(__('tbtop-cms::pages.columns.status'))->options($this->displayStateOptions())
                ->filterUsing(static fn (Builder $q, mixed $value): Builder => PageListState::whereDisplayState($q, $pages, $value)),
            $s->select('seo_state')->label(__('tbtop-cms::pages.columns.seo'))->options($this->seoStateOptions())
                ->filterUsing(static fn (Builder $q, mixed $value): Builder => PageListState::whereSeoState($q, $pages, $value)),
        ];
    }

    /**
     * Only pages that have a child are a useful parent to filter by.
     *
     * @return list<array{value: string, label: string}>
     */
    private function parentOptions(): array
    {
        $default = app(Languages::class)->defaultLocale();

        return Page::query()->whereHas('children')->orderBy('sort_order')->orderBy('id')->get()
            ->map(static fn (Page $page): array => [
                'value' => (string) $page->getKey(),
                'label' => $page->getTranslation('name', $default, false) ?: ('#' . $page->getKey()),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function displayStateOptions(): array
    {
        return array_map(
            static fn (PageDisplayState $state): array => ['value' => $state->value, 'label' => __('tbtop-cms::pages.status.' . $state->value)],
            PageDisplayState::cases(),
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function seoStateOptions(): array
    {
        return array_map(
            static fn (SeoState $state): array => ['value' => (string) $state->value, 'label' => $state->label()],
            SeoState::cases(),
        );
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
            ->selectSub(AuthorNames::subselect($table, 'updated_by'), 'updated_by_name');

        $this->selectSourceBacked($query, $table);

        return PageSortKey::apply(PageListState::select($query, $table));
    }

    /**
     * source_backed: whether the row's type is source-backed, read by the
     * duplicate row action's hiddenIf through the source_backed column.
     * Left out when no such type is registered (a missing field is falsy to
     * the condition).
     *
     * @param  Builder<Page>  $query
     */
    private function selectSourceBacked(Builder $query, string $table): void
    {
        $types = self::sourceBackedTypes();

        if ($types === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($types), '?'));
        $query->selectRaw("CASE WHEN {$table}.type IN ({$placeholders}) THEN 1 ELSE 0 END AS source_backed", $types);
    }

    /** @return list<string> */
    private static function sourceBackedTypes(): array
    {
        $registry = app(PageTypeRegistry::class);

        return array_values(array_filter($registry->keys(), $registry->isSourceBacked(...)));
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

    private function tab(string $name, string $label): Tab
    {
        return Tab::make($name)->label($label)->count()
            ->description(__('tbtop-cms::pages.tab_description', ['tab' => $label]));
    }

    /** Every page but those of a type kept to its own tab (PageTypeInterface::listedInAll()). */
    private function allTab(string $pages): Tab
    {
        $tab = $this->tab('all', __('tbtop-cms::pages.tabs.all'));
        $registry = app(PageTypeRegistry::class);
        $unlisted = $registry->unlistedInAllKeys();
        // A row's own type decides: its subtype when registered, else its base (a listed subtype of an unlisted base stays).
        $listed = array_values(array_diff($registry->keys(), $unlisted));

        return $unlisted === [] ? $tab : $tab->query(static fn (Builder $q): Builder => $q
            ->where(static fn (Builder $sub): Builder => $sub->whereNull("{$pages}.subtype")->orWhereNotIn("{$pages}.subtype", $unlisted))
            ->where(static fn (Builder $sub): Builder => $sub->whereNotIn("{$pages}.type", $unlisted)->orWhereIn("{$pages}.subtype", $listed)));
    }

    /**
     * One tab per type a live page has, registered types in registry order
     * and then any type that outlived its registration, labelled by its key.
     *
     * @return list<Tab>
     */
    private function typeTabs(string $pages): array
    {
        $registry = app(PageTypeRegistry::class);
        $present = Page::query()->distinct()->pluck('type')->all();
        $unregistered = array_diff($present, $registry->keys());
        sort($unregistered);
        // A base type's tab leaves its unlisted subtypes to their own tab, as "All" does.
        $unlisted = $registry->unlistedInAllKeys();

        return [
            ...array_map(
                fn (string $type): Tab => $this->tab($type, PageFormOptions::typeLabelFor($type, $registry->has($type) ? $registry->get($type)->label() : $type))
                    ->query(static fn (Builder $q): Builder => $unlisted === [] ? $q->where("{$pages}.type", $type) : $q->where("{$pages}.type", $type)
                        ->where(static fn (Builder $sub): Builder => $sub->whereNull("{$pages}.subtype")->orWhereNotIn("{$pages}.subtype", $unlisted))),
                [...array_intersect($registry->keys(), $present), ...$unregistered],
            ),
            ...$this->unlistedSubtypeTabs($pages),
        ];
    }

    /**
     * A subtype left out of "All" (a tag under a "Tags" category) still
     * needs a tab of its own: its base's tab mixes it with the rest.
     *
     * @return list<Tab>
     */
    private function unlistedSubtypeTabs(string $pages): array
    {
        $registry = app(PageTypeRegistry::class);
        $subtypes = array_filter(
            $registry->unlistedInAllKeys(),
            static fn (string $key): bool => $registry->get($key)->base() !== $key,
        );
        $present = Page::query()->distinct()->whereIn('subtype', $subtypes)->pluck('subtype')->all();

        return array_values(array_map(
            fn (string $subtype): Tab => $this->tab($subtype, PageFormOptions::typeLabelFor($subtype, $registry->get($subtype)->label()))
                ->query(static fn (Builder $q): Builder => $q->where("{$pages}.subtype", $subtype)),
            array_intersect($subtypes, $present),
        ));
    }

    /** Same label as the type's tab: the translation, then the type's own label(), then the raw key. */
    private function typeLabel(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }
        $types = app(PageTypeRegistry::class);

        return PageFormOptions::typeLabelFor($value, $types->has($value) ? $types->get($value)->label() : $value);
    }

    /**
     * Every registered subtype, whatever its base.
     *
     * @return list<array{value: string, label: string}>
     */
    private function subtypeOptions(): array
    {
        $options = [];
        foreach (app(PageTypeRegistry::class)->all() as $type) {
            if ($type->base() !== $type->key()) {
                $options[] = ['value' => $type->key(), 'label' => PageFormOptions::typeLabelFor($type->key(), $type->label())];
            }
        }

        return $options;
    }

    private function statusLabel(mixed $value): string
    {
        return EnumLabel::make('tbtop-cms::pages.status', $value);
    }

    /**
     * Not EnumLabel::make() like its neighbours: those columns carry names,
     * this one carries a sortable rank, so the enum owns the rank-to-words
     * step (SeoState::labelFor()).
     */
    private function seoStateLabel(mixed $value): string
    {
        return SeoState::labelFor($value);
    }

    private function create(ActionCtx $ctx): Effects
    {
        $page = PageForm::save($ctx->form);

        return Effects::make()->notify(__('tbtop-cms::pages.actions.created'))->closeModal()->redirect(
            route('tbtop.cms.page-edit-page', ['page' => $page->id]),
        );
    }
}
