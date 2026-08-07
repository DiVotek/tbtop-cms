<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Carbon\Exceptions\InvalidFormatException;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PagePublished;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Events\PageUnpublished;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

class PageForm
{
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
        return [
            $s->section(['title' => __('tbtop-cms::pages.sections.content')], [
                $s->text('name')->label(__('tbtop-cms::pages.fields.name'))->required()->translatable(),
                $s->text('title')->label(__('tbtop-cms::pages.fields.title'))->translatable(),
                $s->slug('slug')->label(__('tbtop-cms::pages.fields.slug'))->required()
                    ->translatable()->fromField('name'),
            ]),
            $s->section(['title' => __('tbtop-cms::pages.sections.settings')], [
                $s->select('type')->label(__('tbtop-cms::pages.fields.type'))->required()->options(PageFormOptions::types()),
                $s->select('parent_id')->label(__('tbtop-cms::pages.fields.parent'))->options(PageFormOptions::parents())
                    ->hiddenIf(Cond::notIn('type', ['category', 'article'])),
            ]),
        ];
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
            $s->text('name')->label(__('tbtop-cms::pages.fields.name'))->required()->translatable(),
            $s->text('title')->label(__('tbtop-cms::pages.fields.title'))->translatable(),
            ...($withSlug ? [
                $s->slug('slug')->label(__('tbtop-cms::pages.fields.slug'))->required()
                    ->translatable()->fromField('name')
                    ->helperText(__('tbtop-cms::pages.fields.slug_edit_hint')),
            ] : []),
            $s->text('heading')->label(__('tbtop-cms::pages.fields.heading'))->translatable(),
            $s->textarea('summary')->label(__('tbtop-cms::pages.fields.summary'))->translatable(),
            $s->richtext('body')->label(__('tbtop-cms::pages.fields.body'))->translatable(),
        ];
    }

    /**
     * Fields for the "SEO" tab of the dedicated edit page. is_indexable lives
     * in the sidebar's "Publication" card (PageEditSidebar), not here.
     *
     * @return list<Node|Field>
     */
    public static function seoFields(S $s): array
    {
        return [
            $s->text('seo_title')->label(__('tbtop-cms::pages.fields.seo_title')),
            $s->textarea('seo_description')->label(__('tbtop-cms::pages.fields.seo_description')),
            $s->text('seo_focus_keyword')->label(__('tbtop-cms::pages.fields.seo_focus_keyword')),
            $s->section(['title' => __('tbtop-cms::pages.sections.social')], [
                // Text has no client-side placeholder wiring (unlike textarea);
                // helperText renders instead so the "defaults to..." hint is
                // still visible.
                $s->text('og_title')->label(__('tbtop-cms::pages.fields.og_title'))
                    ->helperText(__('tbtop-cms::pages.fields.og_title_placeholder')),
                $s->textarea('og_description')->label(__('tbtop-cms::pages.fields.og_description'))
                    ->set('placeholder', __('tbtop-cms::pages.fields.og_description_placeholder')),
                $s->media('og_image')->label(__('tbtop-cms::pages.fields.og_image'))->variant('preview'),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     *
     * @throws PageConflictException When $form carries a lock_version that no
     *                               longer matches the row (someone else saved or restored this
     *                               page since the form was loaded). Checked first, before any
     *                               fill/guard side effect, so a stale submit never partially
     *                               applies — see PageEditPage::save()/PageEditBlockTabs for how
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
        }

        $parentId = self::nullableInt($form['parent_id'] ?? null);
        self::guardParent($page, $parentId);
        self::guardTypeAndStatus($page, $form);
        self::guardScheduling($form);

        $page->fill(self::fillAttributes($page, $isCreate ? self::withTitleFallback($form) : $form, $parentId, $isCreate));

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
            $page->save();
        } catch (DomainException $e) {
            $field = $statusDirty ? 'status' : ($hierarchyDirty ? 'type' : 'status');

            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        event(new PageSaved($page, $isCreate));
        self::eventPublishTransition($page, $statusBefore);

        return $page;
    }

    /**
     * Compares the status this save started from against what actually
     * persisted, so a create-published page and a draft->published update
     * both raise PagePublished, and only an update leaving Published raises
     * PageUnpublished.
     */
    private static function eventPublishTransition(Page $page, ?PageStatus $statusBefore): void
    {
        $wasPublished = $statusBefore === PageStatus::Published;
        $isPublished = $page->status === PageStatus::Published;

        if ($isPublished && ! $wasPublished) {
            event(new PagePublished($page));
        } elseif ($wasPublished && ! $isPublished) {
            event(new PageUnpublished($page));
        }
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
            'content' => BlockContentField::toEnvelope($form['content'] ?? null),
            'seo' => self::seoAttribute($form),
        ];

        if ($page->exists && $page->is_system) {
            return $attributes;
        }

        return [
            ...$attributes,
            'type' => (string) ($form['type'] ?? ''),
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
     * @param  array<string, mixed>  $form
     * @return array{title: string, description: string, focus_keyword: string, og_title: string, og_description: string, og_image: string}
     */
    private static function seoAttribute(array $form): array
    {
        return [
            'title' => (string) ($form['seo_title'] ?? ''),
            'description' => (string) ($form['seo_description'] ?? ''),
            'focus_keyword' => (string) ($form['seo_focus_keyword'] ?? ''),
            'og_title' => (string) ($form['og_title'] ?? ''),
            'og_description' => (string) ($form['og_description'] ?? ''),
            'og_image' => (string) ($form['og_image'] ?? ''),
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
            'parent_id' => $page->parent_id !== null ? (string) $page->parent_id : PageFormOptions::NO_PARENT,
            'status' => $page->status->value,
            'published_at' => $page->published_at?->toIso8601String(),
            'unpublish_at' => $page->unpublish_at?->toIso8601String(),
            'is_indexable' => $page->is_indexable,
            'sort_order' => $page->sort_order,
            'paths' => $page->slugs->pluck('path', 'locale')->all(),
            'slug' => $page->slugs->pluck('slug', 'locale')->all(),
            'content' => BlockContentField::toRows($page->content),
            'image' => (string) ($page->image ?? ''),
            'seo_title' => (string) ($seo['title'] ?? ''),
            'seo_description' => (string) ($seo['description'] ?? ''),
            'seo_focus_keyword' => (string) ($seo['focus_keyword'] ?? ''),
            'og_title' => (string) ($seo['og_title'] ?? ''),
            'og_description' => (string) ($seo['og_description'] ?? ''),
            'og_image' => (string) ($seo['og_image'] ?? ''),
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

        $type = (string) ($form['type'] ?? '');
        if (! app(PageTypeRegistry::class)->has($type)) {
            throw ValidationException::withMessages([
                'type' => __('tbtop-cms::pages.validation.type_unregistered', ['type' => $type]),
            ]);
        }

        self::guardStatus((string) ($form['status'] ?? PageStatus::Draft->value));
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
