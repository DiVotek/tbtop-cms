<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Carbon\Exceptions\InvalidFormatException;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Fields\BlockContent;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Block\BlockEnvelope;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaSelector;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeRegistry;
use Tbtop\Cms\Frontend\Seo\SeoChecklist;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\StarterBlocks;
use Tbtop\Cms\Page\Types\PageType;
use Tbtop\Cms\Support\LockedSave;
use Throwable;

class PageForm
{
    /** The create form's per-base subtype selects are named `subtype_{base}`; save() reads the picked type's one. */
    private const string CREATE_SUBTYPE_PREFIX = 'subtype_';

    /**
     * Fields for the pages-list create modal. A deliberately small subset —
     * heading/summary/is_indexable/sort_order/blocks/status are edit-only;
     * is_indexable defaults to true and status to draft server-side on create
     * (see fillAttributes()), and sort_order/blocks are refined after the
     * record exists.
     *
     * @return list<Node>
     */
    public static function fields(S $s): array
    {
        // A parent is always a category: an article's (required unless the host's
        // article type drops requiresParent()), a subcategory's optional one.
        $parent = $s->relation('parent_id')->label(__('tbtop-cms::pages.fields.category'))
            ->query(static fn (): Builder => self::parentQuery())
            ->labelKey('name')->searchable()
            ->hiddenIf(Cond::notIn('type', ['category', PageLeafType::ARTICLE]));
        if (PageLeafType::articleRequiresParent()) {
            $parent->requiredIf(Cond::eq('type', PageLeafType::ARTICLE));
        }

        return [
            $s->section(['title' => __('tbtop-cms::pages.sections.content')], [
                $s->text('name')->label(__('tbtop-cms::pages.fields.name'))->required()->translatable(),
                $s->text('title')->label(__('tbtop-cms::pages.fields.title'))->translatable(),
                $s->slug('slug')->label(__('tbtop-cms::pages.fields.slug'))->required()
                    ->translatable()->fromField('name'),
            ]),
            $s->section(['title' => __('tbtop-cms::pages.sections.settings')], [
                // The generic page is what most creates are; category/article
                // are the deliberate picks. Source-backed types are excluded:
                // creating one manually is not allowed, the storefront sync
                // owns creation (see PageTypeRegistry::isSourceBacked()).
                $s->select('type')->label(__('tbtop-cms::pages.fields.type'))
                    ->required()->options(PageFormOptions::types(includeSourceBacked: false))
                    ->default(app(PageType::class)->key()),
                // One select per base that has subtypes, each shown only for its base: a
                // single `subtype` select cannot swap its options when "Type" changes.
                ...array_merge(...array_map(
                    static fn (array $type): array => self::subtypeField($s, $type['value'], self::CREATE_SUBTYPE_PREFIX . $type['value']),
                    PageFormOptions::types(includeSourceBacked: false),
                )),
                $parent,
            ]),
        ];
    }

    /**
     * The "Subtype" select for pages of $base: absent when $base has no
     * subtypes, hidden once "Type" is changed to another base — its options
     * would not belong to it, and save() drops the stale value. The edit
     * form's select ($isStored) stays until it is cleared: save() refuses a
     * type change that would leave a stored subtype behind, and the editor
     * needs the select to clear it.
     *
     * @return list<Field>
     */
    public static function subtypeField(S $s, string $base, string $name = 'subtype', bool $isStored = false): array
    {
        $options = PageFormOptions::subtypes($base);
        if ($options === []) {
            return [];
        }

        return [
            $s->select($name)->label(__('tbtop-cms::pages.fields.subtype'))
                ->options($options)->default('')
                ->hiddenIf($isStored ? Cond::all(Cond::neq('type', $base), Cond::empty($name)) : Cond::neq('type', $base)),
        ];
    }

    /**
     * Base query backing the parent_id relation picker: only categories are
     * valid parents (the sole grouping container in the page hierarchy — see
     * PageTypeRegistry/CategoryType). Edit forms pass the page being edited
     * so it and its descendants are excluded — placing a page under itself or
     * its own subtree is a cycle guardParent() would reject anyway, but
     * hiding those options keeps the picker from even offering them.
     *
     * @return Builder<Page>
     */
    public static function parentQuery(?Page $excluding = null): Builder
    {
        $query = Page::query()->where('type', 'category')->orderBy('sort_order')->orderBy('id');

        if ($excluding !== null && $excluding->exists) {
            $query->whereNotIn('id', [$excluding->getKey(), ...self::descendantIds($excluding)]);
        }

        return $query;
    }

    /**
     * Fields for the "Основне" tab of the dedicated edit page. status/type/
     * parent_id/sort_order live in the sidebar cards (PageEditSidebar); the
     * block content lives in its own per-block tabs (PageEditBlockTabs) —
     * neither belongs to this tab anymore.
     *
     * $withSlug is false for the site-root system page (Home: is_system with
     * no parent): its slug/path must stay '' (see PageSlugWriter::slugBase()/
     * baseFor()), but RuleWalker collects `required` statically from the
     * field tree, so a required slug field would make it unsaveable — and
     * separately, the client slug field auto-derives from the name when it
     * mounts over an empty value, which would propose "home" and move the
     * site root off '/' on save. Omitting the field entirely avoids both.
     *
     * @return list<Node|Field>
     */
    public static function generalFields(S $s, bool $withSlug = true): array
    {
        return [
            $s->text('name')->label(__('tbtop-cms::pages.fields.name'))->required()->translatable()
                ->helperText(__('tbtop-cms::pages.fields.name_hint')),
            $s->text('title')->label(__('tbtop-cms::pages.fields.title'))->translatable()
                ->helperText(__('tbtop-cms::pages.fields.title_hint')),
            ...($withSlug ? [
                $s->slug('slug')->label(__('tbtop-cms::pages.fields.slug'))->required()
                    ->translatable()->fromField('name')
                    ->helperText(__('tbtop-cms::pages.fields.slug_edit_hint')),
            ] : []),
            $s->text('heading')->label(__('tbtop-cms::pages.fields.heading'))->translatable()
                ->helperText(__('tbtop-cms::pages.fields.heading_hint')),
            $s->textarea('summary')->label(__('tbtop-cms::pages.fields.summary'))->translatable(),
            $s->richtext('body')->label(__('tbtop-cms::pages.fields.body'))->translatable(),
        ];
    }

    /**
     * Fields for the "SEO" tab of the dedicated edit page. is_indexable lives
     * in the sidebar's "Publication" card (PageEditSidebar), not here.
     *
     * seo_title/seo_description/seo_focus_keyword/og_title/og_description are
     * translatable — hreflang variants of the same page routinely want a
     * different meta title/description per locale. og_image stays a plain
     * scalar: an image isn't per-locale, and hreflang pages share it.
     *
     * Placeholders show the COMPUTED final value (template/suffix applied)
     * for the DEFAULT locale only — a static, single-per-field hint (same
     * string in every locale tab) rather than a live per-locale preview; see
     * computedPlaceholders() below. $page is only absent for the create
     * modal, which never renders this tab today, but the guard keeps the
     * method safe if that ever changes.
     *
     * @return list<Node|Field>
     */
    public static function seoFields(S $s, ?Page $page = null): array
    {
        $placeholders = self::computedPlaceholders($page);
        // url:http,https rather than bare 'url': the bare rule accepts any
        // scheme a browser tolerates (javascript:, mailto:, ftp:), and one of
        // those saved as a canonical would either do nothing or do something
        // actively wrong when a crawler or the head tag reads it back out.
        $canonical = $s->text('seo_canonical')->label(__('tbtop-cms::pages.fields.seo_canonical'))
            ->translatable()->rules(['nullable', 'url:http,https'])
            ->helperText(__('tbtop-cms::pages.fields.seo_canonical_hint'));
        self::applyLocaleRules($canonical, ['nullable', 'url:http,https']);

        $fields = [
            $s->text('seo_title')->label(__('tbtop-cms::pages.fields.seo_title'))
                ->translatable()->placeholder($placeholders['title'])
                ->helperText(__('tbtop-cms::pages.fields.seo_title_hint')),
            $s->textarea('seo_description')->label(__('tbtop-cms::pages.fields.seo_description'))
                ->translatable()->set('placeholder', $placeholders['description'])
                ->helperText(__('tbtop-cms::pages.fields.seo_description_hint')),
            $s->text('seo_focus_keyword')->label(__('tbtop-cms::pages.fields.seo_focus_keyword'))
                ->translatable()
                ->helperText(__('tbtop-cms::pages.fields.seo_focus_keyword_hint')),
            $s->section(['title' => __('tbtop-cms::pages.sections.social')], [
                ...OgPreview::build($s, $page, app(Languages::class)->defaultLocale()),
                // og:title/og:description fall back to the UNtemplated meta
                // values (see SeoResolver: ogTitle uses metaTitle, not
                // title()), so their placeholders show ogTitle/ogDescription
                // rather than the templated outputs.
                $s->text('og_title')->label(__('tbtop-cms::pages.fields.og_title'))
                    ->translatable()->placeholder($placeholders['og_title']),
                $s->textarea('og_description')->label(__('tbtop-cms::pages.fields.og_description'))
                    ->translatable()->set('placeholder', $placeholders['og_description']),
                $s->media('og_image')->label(__('tbtop-cms::pages.fields.og_image'))
                    ->variant('preview'),
            ]),
            $canonical,
            ...self::schemaSection($s, $page),
        ];

        $checklist = self::seoChecklistSection($s, $page);

        return [
            // The preview spans the row: its card is as wide as the result
            // column it measures (600px plus padding) and cannot be narrowed
            // without the pixel meter describing a box nobody can see. It
            // renders collapsed, so what actually sits above the fields is one
            // line carrying both numbers.
            ...SerpPreview::build($s, $page, app(Languages::class)->defaultLocale()),
            // Fields left, checks right — the report about the work stops
            // standing between the editor and the work. The checklist reads as
            // short labelled rows, so a third of the row is enough for it, and
            // one column below `lg` puts it back under the fields rather than
            // squeezing both.
            $s->grid(['cols' => ['sm' => 1, 'lg' => 3], 'gap' => 6], [
                $s->stack($fields, ['colSpan' => ['sm' => 1, 'lg' => 2]]),
                ...$checklist,
            ]),
        ];
    }

    /**
     * The structured-data section. What it offers is decided by the page
     * type, which the server already knows — an article is always an Article
     * and a category always a CollectionPage, so neither gets a type picker,
     * and a category needs no fields at all (its hasPart listing comes from
     * the child pages). Only a page type with more than one schema key
     * carries the select at all — one choice is nothing to choose from.
     *
     * Absent for the create modal ($page null/unsaved) for the same reason
     * the checklist is: it renders no SEO tab today.
     *
     * @return list<Node|Field>
     */
    private static function schemaSection(S $s, ?Page $page): array
    {
        if ($page === null || ! $page->exists || ! app(PageTypeRegistry::class)->has($page->type)) {
            return [];
        }

        $keys = app(SchemaSelector::class)->availableKeys($page);

        if (count($keys) < 2) {
            return [];
        }

        $registry = app(SchemaTypeRegistry::class);
        $options = array_map(
            static fn (string $key): array => ['value' => $key, 'label' => $registry->get($key)->label()],
            $keys,
        );

        return [
            $s->section(['title' => __('tbtop-cms::pages.sections.schema')], [
                $s->select('schema_type')->label(__('tbtop-cms::pages.fields.schema_type'))->options($options)
                    ->rules(['nullable', Rule::in($keys)]),
            ]),
        ];
    }

    /**
     * RuleWalker applies a translatable field's own ->rules() to the default
     * locale only (see RuleWalker::fromTranslatableField()); every other
     * active locale needs the same rules via ->rulesForLocale() explicitly,
     * or it would validate as bare 'nullable' and accept anything. Locales
     * come from the same Languages source ContentLocales syncs into
     * config('tbtop-admin.content_locales') for this request.
     *
     * @param  list<string>  $rules
     */
    private static function applyLocaleRules(Field $field, array $rules): Field
    {
        $languages = app(Languages::class);
        $default = $languages->defaultLocale();

        foreach ($languages->adminActive() as $row) {
            if ($row['locale'] !== $default) {
                $field->rulesForLocale($row['locale'], $rules);
            }
        }

        return $field;
    }

    /**
     * Read-only checklist, rendered beside the SEO fields. Computed for every
     * editor locale at once (see EditorLocales::list()), same set SerpPreview
     * resolves — the client picks the active tab's entry, same reasoning as
     * that block's docblock. Absent for the create modal ($page null/
     * unsaved): there is nothing to check yet, and duplicate-title comparison
     * needs a persisted id to exclude itself from the query.
     *
     * Deliberately NOT a liveRegion, unlike SerpPreview/OgPreview: the
     * duplicate-title check runs a database query (SeoChecklist::
     * duplicateTitleCheck()), and every other check reads facts that only
     * change on save (is_indexable, hreflang coverage, the saved og_image) —
     * recomputing a DB query on every keystroke a live region would trigger
     * buys nothing an editor would notice mid-edit. The checklist catches up
     * on save or reload, which is when its facts actually change.
     *
     * A custom node kind rather than display blocks: displayValue() and
     * displayText() are separate blocks stacked vertically, so every check
     * cost two rows — 24 lines for 12 checks, most of the tab's height. The
     * client block puts the status and the label on one line and groups them
     * by whether the check is required.
     *
     * Values travel as data, not markup, so nothing here needs escaping:
     * React renders them as text nodes.
     *
     * @return list<Node>
     */
    private static function seoChecklistSection(S $s, ?Page $page): array
    {
        if ($page === null || ! $page->exists) {
            return [];
        }

        $defaultLocale = app(Languages::class)->defaultLocale();
        $checklist = app(SeoChecklist::class);

        $byLocale = [];
        foreach (EditorLocales::list($defaultLocale) as $locale) {
            $byLocale[$locale] = array_map(
                static fn (array $check): array => [
                    ...$check,
                    'statusWord' => __('tbtop-cms::pages.seo_checklist.status.' . $check['status']),
                ],
                $checklist->for($page, $locale),
            );
        }

        return [
            new Node('cms:seoChecklist', [
                'byLocale' => $byLocale,
                'defaultLocale' => $defaultLocale,
                'labels' => [
                    'required' => __('tbtop-cms::pages.seo_checklist.groups.required'),
                    'optional' => __('tbtop-cms::pages.seo_checklist.groups.optional'),
                ],
            ]),
        ];
    }

    /**
     * Computed values a blank SEO field would actually resolve to, for the
     * default locale — reuses SeoResolver rather than re-deriving the
     * title/description cascade a second time. null $page (create modal, not
     * reached today) or an unsaved record yields empty placeholders.
     *
     * @return array{title: string, description: string, og_title: string, og_description: string}
     */
    private static function computedPlaceholders(?Page $page): array
    {
        if ($page === null || ! $page->exists) {
            return ['title' => '', 'description' => '', 'og_title' => '', 'og_description' => ''];
        }

        $locale = app(Languages::class)->defaultLocale();
        // Breadcrumbs passed for the same reason SerpPreview and SeoChecklist
        // pass them: a placeholder promising what an empty field resolves to
        // must not drop a {parent} segment the frontend renders.
        $resolved = app(SeoResolver::class)->resolve(
            $page,
            $locale,
            app(Breadcrumbs::class)->ancestors($page, $locale),
        );

        return [
            'title' => $resolved['title'],
            'description' => $resolved['description'],
            'og_title' => $resolved['ogTitle'],
            'og_description' => $resolved['ogDescription'],
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     *
     * @throws PageConflictException When $form carries a lock_version that no
     *                               longer matches the row (someone else saved or restored this
     *                               page since the form was loaded). Checked first, before any
     *                               fill/guard side effect, so a stale submit never partially
     *                               applies, and again at write time by PageObserver's atomic
     *                               claim, which a save racing this one loses — see
     *                               PageEditPage::save()/PageEditBlockTabs for how
     *                               callers turn this into a non-destructive error notify that
     *                               keeps the submitter's unsaved edits in the form.
     */
    public static function save(array $form, ?int $id = null): Page
    {
        $page = $id !== null ? Page::query()->findOrFail($id) : new Page;
        $isCreate = ! $page->exists;
        $statusBefore = $page->exists ? $page->status : null;

        // Creates and any legacy/internal caller that never learned about
        // lock_version (the form simply omits the key — see loadRow()'s
        // counterpart and PagesPage::createAction()'s form, which has none)
        // skip the check outright: there is no prior version to conflict
        // with, and requiring the key would break every caller that predates
        // this feature.
        if (! $isCreate && array_key_exists('lock_version', $form)) {
            self::guardLockVersion($page, $form);
            // PageObserver claims the row against it at write time.
            $page->expectedLockVersion = (int) $form['lock_version'];
        }

        if ($isCreate && is_string($form['type'] ?? null) && array_key_exists(self::CREATE_SUBTYPE_PREFIX . $form['type'], $form)) {
            $form['subtype'] = $form[self::CREATE_SUBTYPE_PREFIX . $form['type']];
        }

        $parentId = self::nullableInt($form['parent_id'] ?? null);
        if (! ($page->exists && $page->is_system) && is_string($form['type'] ?? null)) {
            ['type' => $form['type'], 'parentId' => $parentId] = PageLeafType::resolve($page, $form['type'], $parentId);
            // A submitted null clears the subtype (empty strings arrive as null), so only a default is written back.
            $default = PageLeafType::defaultSubtype($page, $form['type'], $parentId, array_key_exists('subtype', $form) ? $form['subtype'] : $page->subtype);
            if ($default !== null) {
                $form['subtype'] = $default;
            }
        }
        self::guardParent($page, $parentId);
        self::guardTypeAndStatus($page, $form);
        self::guardScheduling($form);

        // Read before fill() overwrites it — content's tpl_id provenance
        // (see fillContent()'s docblock) is carried over from THIS, the
        // pre-save envelope, by matching block id.
        $contentBefore = $isCreate ? [] : BlockEnvelope::blocksOf($page->content);
        // Folded against the types the form was rendered for, before fill() may change them.
        $fields = $isCreate ? [] : PageEditTypeFields::fold($form, $page);

        $page->fill(self::fillAttributes($page, $isCreate ? self::withTitleFallback($form) : $form, $parentId, $isCreate));

        // Null stays null when nothing was entered, so a plain save is not an editorial change.
        if ($fields !== ($page->fields ?? [])) {
            $page->fields = $fields;
        }

        // A source-backed page's content is owned by its type's template
        // (PageTypeInterface::template()), never by this form — the editor
        // hides the block tabs (see PageEditPage), but PageEditBlocks::toRows()
        // still computes a 'content' form key from whatever it read off the
        // page, and that must not overwrite the null that means "render the
        // template". content (and starter_blocks, which only a category uses)
        // stay exactly as stored.
        if (! self::isSourceBacked($page)) {
            $page->content = self::fillContent($page, $form, $contentBefore, $isCreate);
            $page->starter_blocks = self::fillStarterBlocks($page, $form);
        }

        // Left unconditional rather than gated on ! $page->is_system: the
        // edit form never submits a 'slug' key for the root system page
        // (generalFields() omits the field — see its docblock), and even if
        // a stray override reached here, PageSlugWriter::baseFor() ignores
        // overrides for any is_system page outright. Gating here too would
        // be redundant defense against the same already-guarded case.
        $page->pendingSlugOverrides = self::stringMap($form['slug'] ?? null);

        // PageObserver enforces invariants (system-page status lock, type/
        // parent hierarchy rules) as DomainException during save(). The UI
        // already avoids submitting a changed status/type/parent for system
        // pages (see fillAttributes()/guardTypeAndStatus() above), so this is
        // defense-in-depth for any caller that reaches save() without going
        // through those guards — without it, the exception would bubble out
        // of the form's onSubmit handler as an unhandled 500 instead of a
        // field error. The field picked mirrors exactly what the observer
        // itself inspects (dirty status vs. dirty type/parent), not the
        // exception's message text.
        $statusDirty = $page->isDirty('status');
        $hierarchyDirty = $page->isDirty(['type', 'parent_id']);

        try {
            $save = static fn (): bool => $page->save();
            $event = ! $isCreate && $statusBefore !== $page->status
                ? ($page->status === PageStatus::Published ? 'published' : 'unpublished')
                : null;

            // One transaction: a failure after the lock claim (revision, slugs, refs) rolls the claim back too.
            LockedSave::run(
                static fn (): mixed => $event !== null ? app(AuditContext::class)->during($event, [], $save) : $save(),
                static fn (Throwable $e): PageConflictException => new PageConflictException("Page #{$page->getKey()} hit a lock while saving.", 0, $e),
            );
        } catch (PageConflictException $e) {
            throw $e;
        } catch (DomainException $e) {
            $field = $statusDirty ? 'status' : ($hierarchyDirty ? 'type' : 'status');

            throw ValidationException::withMessages([$field => $e->getMessage()]);
        } finally {
            $page->expectedLockVersion = null;
        }

        return $page;
    }

    /**
     * The `content` envelope, computed separately from fillAttributes()'s
     * flat mass-assignment because it needs two things fillAttributes()
     * doesn't have: the page's OWN pre-fill content (for tpl_id carry-over,
     * update only) and its POST-fill type/parent_id (for starter-block
     * inheritance, create only) — $page has already been fill()ed with type/
     * parent_id/etc. by the time this runs (see save()).
     *
     * Create with no submitted content (the create-modal form has no block
     * repeater — see PageForm::fields()) inherits from StarterBlocks
     * instead of starting empty; a create THAT does submit content (e.g. a
     * duplicate-via-form flow, if one is ever added) is trusted as-is and
     * skips inheritance, since the caller already decided what content this
     * page should start with.
     *
     * @param  array<string, mixed>  $form
     * @param  list<array<string, mixed>>  $contentBefore
     * @return list<array<string, mixed>>
     */
    private static function fillContent(Page $page, array $form, array $contentBefore, bool $isCreate): array
    {
        if ($isCreate && ! array_key_exists('content', $form)) {
            return app(StarterBlocks::class)->contentFor($page);
        }

        $envelope = BlockContent::toEnvelope($form['content'] ?? null);

        return $isCreate ? $envelope : self::withCarriedTplIds($envelope, $contentBefore);
    }

    /**
     * Re-attaches each block's tpl_id (starter-block provenance — see
     * StarterBlocks/StarterBlockSync) by matching the persisted 'id'.
     *
     * tpl_id is not a declared BlockContent sub-field, so it carries no
     * validation rule; Illuminate\Http\Request::validate() (FormSubmit
     * Controller) drops any form key without one before PageForm::save()
     * ever sees it — the admin repeater's client state keeps tpl_id on rows
     * it never touched (plain object spread on edit), but that state never
     * reaches here. Re-deriving it from the row BEFORE this save, rather
     * than trusting the submitted form, is the only way it survives an
     * unrelated edit to the same page.
     *
     * @param  list<array<string, mixed>>  $envelope
     * @param  list<array<string, mixed>>  $contentBefore
     * @return list<array<string, mixed>>
     */
    private static function withCarriedTplIds(array $envelope, array $contentBefore): array
    {
        $tplIdById = [];
        foreach ($contentBefore as $block) {
            if (isset($block['id'], $block['tpl_id'])) {
                $tplIdById[(string) $block['id']] = $block['tpl_id'];
            }
        }

        return array_map(
            static function (array $block) use ($tplIdById): array {
                $tplId = $tplIdById[(string) ($block['id'] ?? '')] ?? null;

                return $tplId === null ? $block : [...$block, 'tpl_id' => $tplId];
            },
            $envelope,
        );
    }

    /**
     * `starter_blocks` is never edited through this form's own fields — it
     * has no repeater or input of its own (see PageEditStarterBlocks: every
     * change is a dedicated instant-save server action, add/remove/move/
     * apply, the same shape as PageEditBlockTabs's block actions). Those
     * actions pass the already-computed, fully-shaped array through under
     * this raw key; every other caller (the general pageEditor submit,
     * create) omits the key entirely and the existing value is kept as-is.
     *
     * @param  array<string, mixed>  $form
     * @return array{article?: list<array<string, mixed>>, category?: list<array<string, mixed>>}
     */
    private static function fillStarterBlocks(Page $page, array $form): array
    {
        if (array_key_exists('starter_blocks', $form) && is_array($form['starter_blocks'])) {
            return $form['starter_blocks'];
        }

        return is_array($page->starter_blocks) ? $page->starter_blocks : [];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function fillAttributes(Page $page, array $form, ?int $parentId, bool $isCreate): array
    {
        $attributes = [
            'name' => $form['name'] ?? [],
            'title' => $form['title'] ?? [],
            'heading' => $form['heading'] ?? [],
            'summary' => $form['summary'] ?? [],
            'body' => $form['body'] ?? [],
            // Media id string, not a URL — resolved to a URL on the frontend
            // via MediaUrlResolver. Applies to system pages too, so it stays
            // in the unconditional part below rather than the is_system gate.
            'image' => self::nullableMediaId($form['image'] ?? null),
            // The create form has no is_indexable field — new pages are always
            // indexable by default; edits keep the form-submitted value.
            'is_indexable' => $isCreate ? true : (bool) ($form['is_indexable'] ?? false),
            'sort_order' => (int) ($form['sort_order'] ?? 0),
            'seo' => self::seoAttribute($page, $form),
        ];

        if ($page->exists && $page->is_system) {
            return $attributes;
        }

        $type = (string) ($form['type'] ?? (self::isSourceBacked($page) ? $page->type : ''));

        return [
            ...$attributes,
            // Omitted entirely for a source-backed page (PageEditSidebar
            // hides the type select — its type is owned by the sync, not
            // editorially chosen): $type keeps the page's existing type
            // rather than reading a missing key as ''.
            'type' => $type,
            'subtype' => self::subtypeAttribute($page, $form, $type),
            'parent_id' => $parentId,
            'status' => (string) ($form['status'] ?? PageStatus::Draft->value),
            // Create form never sends these (PageEditSidebar is edit-only),
            // so they stay null until the page is first edited — matching
            // status's own create-time default.
            'published_at' => self::nullableDateTime($form['published_at'] ?? null),
            'unpublish_at' => self::nullableDateTime($form['unpublish_at'] ?? null),
        ];
    }

    /**
     * A subtype must be one of $type's. A missing `subtype` key keeps the
     * stored one. A type change that would leave the stored subtype behind is
     * rejected; any other value that does not fit is dropped when the type
     * changed (a stale create-form select) and rejected otherwise — except the
     * page's own stored subtype, which outlives its registration the way
     * `type` does.
     *
     * @param  array<string, mixed>  $form
     */
    private static function subtypeAttribute(Page $page, array $form, string $type): ?string
    {
        $stored = $page->getAttribute('subtype');
        $value = array_key_exists('subtype', $form) ? $form['subtype'] : $stored;
        if (! is_string($value) || $value === '') {
            return null;
        }

        $subtypes = array_map(static fn (PageTypeInterface $subtype): string => $subtype->key(), app(PageTypeRegistry::class)->subtypesOf($type));
        $isTypeKept = $page->exists && $page->type === $type;

        if (in_array($value, $subtypes, true) || ($isTypeKept && $value === $stored)) {
            return $value;
        }
        // Retyping a page would silently strip its subtype (and with it the
        // subtype's fields from the form); the editor clears it first instead.
        if ($page->exists && ! $isTypeKept && $value === $stored) {
            throw ValidationException::withMessages([
                'type' => __('tbtop-cms::pages.validation.type_change_subtype', ['subtype' => $stored]),
            ]);
        }
        if (! $isTypeKept) {
            return null;
        }

        throw ValidationException::withMessages([
            'subtype' => __('tbtop-cms::pages.validation.subtype_invalid', ['subtype' => $value, 'type' => $type]),
        ]);
    }

    /**
     * Create-only: when a locale's title is empty but its name is set, fall
     * the title back to the name — the create form has no heading/summary
     * but title still drives <title>/OG tags, so it should not start blank.
     * Edits never run this: an admin clearing the title on the edit page
     * means it, and re-filling it from the name would override that intent.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function withTitleFallback(array $form): array
    {
        $name = self::stringMap($form['name'] ?? null);
        $title = self::stringMap($form['title'] ?? null);

        foreach ($name as $locale => $value) {
            if ($value !== '' && ($title[$locale] ?? '') === '') {
                $title[$locale] = $value;
            }
        }

        $form['title'] = $title;

        return $form;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $locale => $entry) {
            if (is_string($locale)) {
                $map[$locale] = (string) $entry;
            }
        }

        return $map;
    }

    /**
     * Overlays the form-owned keys on the stored bag, so keys the form does not own (a host's) survive.
     * `schema` is written only when the form sent `schema_type`; otherwise the stored value stays.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function seoAttribute(Page $page, array $form): array
    {
        $stored = is_array($page->seo) ? $page->seo : [];

        return [
            ...$stored,
            'title' => self::stringMap($form['seo_title'] ?? null),
            'description' => self::stringMap($form['seo_description'] ?? null),
            'focus_keyword' => self::stringMap($form['seo_focus_keyword'] ?? null),
            'og_title' => self::stringMap($form['og_title'] ?? null),
            'og_description' => self::stringMap($form['og_description'] ?? null),
            'og_image' => (string) ($form['og_image'] ?? ''),
            'canonical' => self::stringMap($form['seo_canonical'] ?? null),
            ...(array_key_exists('schema_type', $form) ? ['schema' => ['type' => (string) $form['schema_type']]] : []),
        ];
    }

    /** @return array<string, mixed> */
    public static function loadRow(int $id): array
    {
        $page = Page::query()->with('slugs')->findOrFail($id);
        $seo = is_array($page->seo) ? $page->seo : [];

        return [
            'name' => $page->getTranslations('name'),
            'title' => $page->getTranslations('title'),
            'heading' => $page->getTranslations('heading'),
            'summary' => $page->getTranslations('summary'),
            'body' => $page->getTranslations('body'),
            'type' => $page->type,
            'subtype' => $page->subtype ?? '',
            // Raw id string (or null) — the relation picker resolves its
            // label via the relation-search endpoint's resolve mode, which
            // looks the value up by primary key; the old NO_PARENT sentinel
            // would 404 there instead of rendering an empty picker.
            'parent_id' => $page->parent_id !== null ? (string) $page->parent_id : null,
            'status' => $page->status->value,
            'published_at' => $page->published_at?->toIso8601String(),
            'unpublish_at' => $page->unpublish_at?->toIso8601String(),
            'is_indexable' => $page->is_indexable,
            'sort_order' => $page->sort_order,
            'paths' => $page->slugs->pluck('path', 'locale')->all(),
            'slug' => $page->slugs->pluck('slug', 'locale')->all(),
            'content' => BlockContent::toRows($page->content),
            'image' => (string) ($page->image ?? ''),
            // Passed through raw (scalar or locale map) rather than cast to
            // string: TranslatableRecord::normalize() expands a legacy
            // scalar into a locale map client-side (see the field's
            // ->translatable() docs), so casting here would collapse an
            // already-translated map back into a single string.
            'seo_title' => $seo['title'] ?? '',
            'seo_description' => $seo['description'] ?? '',
            'seo_focus_keyword' => $seo['focus_keyword'] ?? '',
            'og_title' => $seo['og_title'] ?? '',
            'og_description' => $seo['og_description'] ?? '',
            'og_image' => (string) ($seo['og_image'] ?? ''),
            'seo_canonical' => $seo['canonical'] ?? '',
            ...self::schemaRow($page),
            // Seeds the pageEditor form's hidden lock_version field (see
            // PageEditPage::view()). The client would round-trip an
            // undeclared record key on its own, but FormSubmitController's
            // $request->validate() strips keys RuleWalker collected no rule
            // for — the value only survives the forms endpoint because a
            // real (hidden) field exists for it in the tree.
            'lock_version' => $page->lock_version,
        ];
    }

    /**
     * Seeds `schema_type` with the effective key (via SchemaSelector), not
     * the raw stored value — a page saved before this key existed, or with a
     * key that drifted out of its type's schemaTypes(), opens showing the
     * type's actual default instead of an empty select.
     *
     * @return array{schema_type: string}
     */
    private static function schemaRow(Page $page): array
    {
        return ['schema_type' => app(SchemaSelector::class)->selectFor($page)->key()];
    }

    /**
     * @param  array<string, mixed>  $form
     *
     * @throws PageConflictException
     */
    private static function guardLockVersion(Page $page, array $form): void
    {
        if ((int) $form['lock_version'] !== $page->lock_version) {
            throw new PageConflictException("Page #{$page->getKey()} was changed since this form was loaded.");
        }
    }

    private static function guardParent(Page $page, ?int $parentId): void
    {
        if ($parentId === null || ! $page->exists) {
            return;
        }

        if ($parentId === $page->getKey() || in_array($parentId, self::descendantIds($page), true)) {
            throw ValidationException::withMessages([
                'parent_id' => __('tbtop-cms::pages.validation.parent_cycle'),
            ]);
        }
    }

    /** @param array<string, mixed> $form */
    private static function guardTypeAndStatus(Page $page, array $form): void
    {
        if ($page->exists && $page->is_system) {
            return;
        }

        $type = (string) ($form['type'] ?? (self::isSourceBacked($page) ? $page->type : ''));
        $registry = app(PageTypeRegistry::class);

        if (! $registry->has($type)) {
            throw ValidationException::withMessages([
                'type' => __('tbtop-cms::pages.validation.type_unregistered', ['type' => $type]),
            ]);
        }

        // Creating a page of a source-backed type by hand, or retyping an
        // existing content-only page into one, is not allowed — the
        // storefront sync owns creation for these types (see
        // PageTypeRegistry::isSourceBacked()). A page already saved with
        // this exact type is left alone: the editor omits the type field
        // entirely for a source-backed page (PageEditSidebar), so this only
        // ever rejects a request built outside that form.
        if ($registry->isSourceBacked($type) && $type !== $page->type) {
            throw ValidationException::withMessages([
                'type' => __('tbtop-cms::pages.validation.type_source_backed', ['type' => $type]),
            ]);
        }

        self::guardStatus((string) ($form['status'] ?? PageStatus::Draft->value));
    }

    /** An unsaved or unregistered-type page is never source-backed. */
    private static function isSourceBacked(Page $page): bool
    {
        // getAttribute(), not ->type: callers run before fill(), where the
        // attribute is still unset despite the model's `string` docblock.
        $type = $page->getAttribute('type');
        if (! is_string($type) || $type === '') {
            return false;
        }

        $registry = app(PageTypeRegistry::class);

        return $registry->has($type) && $registry->isSourceBacked($type);
    }

    private static function guardStatus(string $status): void
    {
        if (PageStatus::tryFrom($status) === null) {
            throw ValidationException::withMessages([
                'status' => __('tbtop-cms::pages.validation.status_invalid', ['status' => $status]),
            ]);
        }
    }

    /**
     * A schedule where the page would go dark before (or the instant) it
     * goes live is never satisfiable, so it's rejected outright rather than
     * silently producing a page that's never actually visible. Only runs
     * when both dates are present — either one alone is always valid.
     *
     * @param  array<string, mixed>  $form
     */
    private static function guardScheduling(array $form): void
    {
        $publishedAt = self::nullableDateTime($form['published_at'] ?? null);
        $unpublishAt = self::nullableDateTime($form['unpublish_at'] ?? null);

        if ($publishedAt === null || $unpublishAt === null) {
            return;
        }

        if ($unpublishAt->lessThanOrEqualTo($publishedAt)) {
            throw ValidationException::withMessages([
                'unpublish_at' => __('tbtop-cms::pages.validation.unpublish_before_published'),
            ]);
        }
    }

    /** @return list<int> */
    private static function descendantIds(Page $page): array
    {
        $ids = [];
        $frontier = [$page->getKey()];

        while ($frontier !== []) {
            $children = Page::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $children = array_values(array_diff($children, $ids));
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    /**
     * The relation picker clears to null/'' natively; PageFormOptions::NO_PARENT
     * stays tolerated here as a regression guard for the legacy select sentinel
     * (any still-cached client bundle, or a stored draft submitted from one).
     */
    private static function nullableInt(mixed $value): ?int
    {
        if (in_array($value, [null, '', PageFormOptions::NO_PARENT], true)) {
            return null;
        }

        return (int) $value;
    }

    private static function nullableMediaId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * The datetime field posts an ISO string or null (cleared) — see
     * dateField.tsx's DateTimeForm. An unparsable string is treated as
     * empty rather than throwing, matching the field's own clear semantics.
     */
    private static function nullableDateTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
