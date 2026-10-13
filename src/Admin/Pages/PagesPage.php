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
use Tbtop\Cms\Admin\Pages\Support\PageFormOptions;
use Tbtop\Cms\Admin\Pages\Support\PageSortKey;
use Tbtop\Cms\Admin\Support\AuthorNames;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EnumLabel;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageDisplayState;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Enums\SeoState;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Events\PageRestored;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\PageTypeRegistry;
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
        $subtypes = $this->subtypeOptions();

        $table = $s->table('pages')
            ->query($this->query(...))
            ->columns([
                Column::make('name')->label(__('tbtop-cms::pages.columns.title'))->translatable()->emphasized(),
                Column::make('type')->label(__('tbtop-cms::pages.columns.type'))
                    ->formatUsing($this->typeLabel(...))
                    ->muted()->uppercase(),
                // Only once a host registers a subtype: before that every row would be blank.
                ...($subtypes === [] ? [] : [
                    Column::make('subtype')->label(__('tbtop-cms::pages.columns.subtype'))
                        ->formatUsing($this->subtypeLabel(...))
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
                // name — see seoStateSql().
                Column::make('seo_state')->label(__('tbtop-cms::pages.columns.seo'))
                    ->formatUsing($this->seoStateLabel(...))
                    ->tooltip($this->seoStateTooltip(...))
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
            ])
            ->tabs([
                $this->tab('all', __('tbtop-cms::pages.tabs.all')),
                ...$this->typeTabs($pages),
                Tab::make('deleted')->label(__('tbtop-cms::pages.tabs.deleted'))->count()
                    ->description(__('tbtop-cms::pages.tabs_deleted_description'))
                    ->query($this->onlyTrashedScope(...)),
            ])
            ->filters($subtypes === [] ? [] : [
                // Qualified: PageSortKey self-joins the pages table, so a bare `subtype` is ambiguous.
                $s->select('subtype')->label(__('tbtop-cms::pages.columns.subtype'))->options($subtypes)
                    ->filterUsing(static fn (Builder $q, mixed $value): Builder => $q->where("{$pages}.subtype", $value)),
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
     * `name`/`title`/`summary` hold any content in ANY locale (see
     * emptyTranslatableSql()), not whether the resolved value for one
     * particular locale is empty. A page with content
     * only in a non-default locale reads as "has a title source" here even
     * if the default locale's slice is blank — a false pass, not a false
     * fail, which is the safer direction for a list-page hint whose
     * authoritative detail lives in the per-page SEO checklist
     * (SeoChecklist::for()).
     *
     * Selected as SeoState's numeric rank, not its name, so the column sorts
     * worst-first (see the enum). The ranks are interpolated from the enum
     * rather than bound: they are its own integer cases, never user input, and
     * a CASE arm cannot take a placeholder on every driver alike.
     */
    private function seoStateSql(string $table): string
    {
        $emptyTitle = $this->emptyTranslatableSql($table, 'title');
        $emptyName = $this->emptyTranslatableSql($table, 'name');
        $emptySummary = $this->emptyTranslatableSql($table, 'summary');

        $fail = SeoState::Fail->value;
        $warn = SeoState::Warn->value;
        $pass = SeoState::Pass->value;

        return <<<SQL
            CASE
                WHEN {$emptyName} AND {$emptyTitle} THEN {$fail}
                WHEN {$emptySummary} OR NOT {$table}.is_indexable THEN {$warn}
                ELSE {$pass}
            END as seo_state
            SQL;
    }

    /**
     * True when a translatable column holds no content in any locale.
     *
     * An unset translatable column is not NULL — the cast writes `[]` for an
     * empty map, so emptiness cannot be tested with IS NULL. Clearing a field
     * that HAD content is the harsher case: the locale key survives with a
     * blank value, so the column stores `{"en":""}`, which a comparison
     * against `[]`/`{}` reads as populated and badges Good.
     *
     * Distinguishing "no keys" from "keys with blank values" means asking the
     * JSON itself, and the three drivers spell that differently — hence the
     * per-driver branch, following PageSortKey::padId()'s precedent for the
     * cases where portable SQL genuinely cannot express the question. Each
     * branch concatenates every value in the map and asks whether the result
     * is empty, so one non-blank locale is enough to count as content.
     *
     * Whitespace-only values still read as content; that imprecision is a
     * false pass, the safe direction for a list hint whose authoritative view
     * is the per-page checklist (SeoChecklist::for()).
     */
    private function emptyTranslatableSql(string $table, string $column): string
    {
        $col = "{$table}.{$column}";

        return match (DB::connection()->getDriverName()) {
            // json_each tolerates arrays and scalars, so `[]` and `""` need no
            // type guard; `type = 'text'` keeps the test to string values.
            'sqlite' => "NOT EXISTS (SELECT 1 FROM json_each({$col}) WHERE type = 'text' AND value <> '')",
            // JSON_SEARCH matches string scalars only and returns NULL when
            // nothing matches. The pattern is '_%', not '%': `%` matches zero
            // characters, so it would match `""` and read blank as content.
            // The JSON_TYPE guard keeps `[]`/`""` off the search, and short-
            // circuits before JSON_SEARCH sees a non-object.
            'mysql', 'mariadb' => "({$col} IS NULL OR JSON_TYPE({$col}) <> 'OBJECT' OR JSON_SEARCH({$col}, 'one', '_%') IS NULL)",
            // jsonb_each_text() raises a hard ERROR on `[]` — CASE branch
            // order does not reliably protect a subquery from evaluation, so
            // this uses jsonpath instead: lax mode (the default) converts the
            // structural error on a non-object into an empty sequence.
            // COALESCE is load-bearing — on a SQL NULL the function returns
            // NULL, and `NOT NULL` is NULL rather than true.
            'pgsql' => "NOT jsonb_path_exists(COALESCE({$col}, 'null')::jsonb, 'lax \$.* ? (@.type() == \"string\" && @ <> \"\")')",
            // Unknown driver: the literal test. It misses blank locale entries,
            // which is a false pass — never a false fail.
            default => "COALESCE({$col}, '[]') IN ('[]', '{}', '\"\"')",
        };
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
            ->url('/{path}')->openUrlInNewTab()
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
        $page = Page::findOrFail((int) $ctx->key());

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
        $page = Page::withTrashed()->findOrFail((int) $ctx->key());

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
        $page = Page::withTrashed()->findOrFail((int) $ctx->key());

        try {
            app(AuditContext::class)->during(
                'force_deleted',
                [],
                static fn (): ?bool => $page->forceDelete(),
            );
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        event(new PageDeleted($page, forced: true));

        return Effects::make()->notify(__('tbtop-cms::pages.actions.force_deleted'))->refreshTable();
    }

    private function tab(string $name, string $label): Tab
    {
        return Tab::make($name)->label($label)->count()
            ->description(__('tbtop-cms::pages.tab_description', ['tab' => $label]));
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

        return array_map(
            fn (string $type): Tab => $this->tab($type, PageFormOptions::typeLabelFor($type, $registry->has($type) ? $registry->get($type)->label() : $type))
                ->query(static fn (Builder $q): Builder => $q->where("{$pages}.type", $type)),
            [...array_intersect($registry->keys(), $present), ...$unregistered],
        );
    }

    private function typeLabel(mixed $value): string
    {
        return EnumLabel::make('tbtop-cms::pages.types', $value);
    }

    private function subtypeLabel(mixed $value): string
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

    /**
     * What the badge is actually complaining about, as a hover line.
     *
     * Derived from the row the table already loaded — no SeoChecklist::for()
     * call, which costs several queries per page and would turn a 50-row list
     * into hundreds of them. That means this reads the same three facts
     * seoStateSql() does and phrases them; the two must stay in step, so the
     * conditions below mirror that CASE arm for arm rather than asking a
     * richer question the badge cannot answer.
     *
     * A page with nothing wrong gets no tooltip: attachTooltips() drops empty
     * strings, so the badge simply has none.
     */
    private function seoStateTooltip(Page $row): string
    {
        $reasons = [];

        // seo.title is checked here but NOT in seoStateSql(): reaching a
        // nested JSON key needs a different dialect per driver, two of which
        // CI cannot exercise (see that method's docblock). So a page carrying
        // only a meta title still badges red — but it must not be TOLD it has
        // no title when it has one. Skipping the line leaves the badge
        // unexplained, which is the honest reading: the badge is being
        // conservative, and the page's own SEO tab has the real verdict.
        if (self::allTranslationsBlank($row, 'name')
            && self::allTranslationsBlank($row, 'title')
            && ! self::hasSeoTitle($row)
        ) {
            $reasons[] = __('tbtop-cms::pages.seo_state_reasons.title');
        }

        if (self::allTranslationsBlank($row, 'summary')) {
            $reasons[] = __('tbtop-cms::pages.seo_state_reasons.summary');
        }

        if (! $row->is_indexable) {
            $reasons[] = __('tbtop-cms::pages.seo_state_reasons.not_indexable');
        }

        return implode("\n", $reasons);
    }

    /**
     * True when the page carries a hand-written meta title in any locale.
     * The `seo` bag is already on the loaded row, so this reads it directly
     * — see the note in seoStateTooltip() for why the badge's SQL cannot.
     */
    private static function hasSeoTitle(Page $row): bool
    {
        $seo = is_array($row->seo) ? $row->seo : [];
        $title = $seo['title'] ?? null;

        if (is_string($title)) {
            return $title !== '';
        }

        foreach ((array) $title as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The PHP counterpart of emptyTranslatableSql(): true when a translatable
     * column holds no non-empty value in any locale. Reads the already-loaded
     * attribute, so it costs nothing per row.
     *
     * Compared against '' and NOT trimmed, deliberately: the SQL counts a
     * whitespace-only value as content (`value <> ''`, documented there as a
     * false pass in the safe direction). Trimming here would be the stricter
     * rule, which sounds better in isolation but makes the tooltip contradict
     * the badge it explains — a page with a single-space summary would badge
     * Good and be told "No summary".
     */
    private static function allTranslationsBlank(Page $row, string $attribute): bool
    {
        foreach ($row->getTranslations($attribute) as $value) {
            if ((string) $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function create(ActionCtx $ctx): Effects
    {
        $page = PageForm::save($ctx->form);

        return Effects::make()->notify(__('tbtop-cms::pages.actions.created'))->closeModal()->redirect(
            route('tbtop.cms.page-edit-page', ['page' => $page->id]),
        );
    }
}
