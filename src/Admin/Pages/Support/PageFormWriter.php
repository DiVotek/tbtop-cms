<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use DomainException;
use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Admin\Fields\BlockContent;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Block\BlockEnvelope;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\StarterBlocks;
use Tbtop\Cms\Page\Support\TypeTemplateContent;
use Tbtop\Cms\Support\LockedSave;
use Throwable;

/** Creates or updates a page from its submitted form, behind PageForm::save(). */
final class PageFormWriter
{
    /**
     * @param  array<string, mixed>  $form
     *
     * @throws PageConflictException See PageForm::save().
     */
    public static function save(array $form, ?int $id = null): Page
    {
        $page = $id !== null ? Page::query()->findOrFail($id) : new Page;
        $isCreate = ! $page->exists;
        $statusBefore = $page->exists ? $page->status : null;

        // Creates and any legacy/internal caller that never learned about
        // lock_version (the form simply omits the key — see PageForm::loadRow()'s
        // counterpart and PagesPage::createAction()'s form, which has none)
        // skip the check outright: there is no prior version to conflict
        // with, and requiring the key would break every caller that predates
        // this feature.
        $expectedLockVersion = null;
        if (! $isCreate && array_key_exists('lock_version', $form)) {
            PageFormGuards::lockVersion($page, $form);
            $expectedLockVersion = (int) $form['lock_version'];
        }

        [$form, $parentId] = self::resolveTypeAndParent($page, $form, $isCreate);
        PageFormGuards::parent($page, $parentId);
        PageFormGuards::typeAndStatus($page, $form);
        PageFormGuards::scheduling($form);

        self::fill($page, $form, $parentId, $isCreate);
        self::persist($page, $expectedLockVersion, $statusBefore, $isCreate);

        return $page;
    }

    /**
     * Picks the create form's per-base subtype select, then lets
     * PageLeafType settle the leaf type, parent and default subtype.
     *
     * @param  array<string, mixed>  $form
     * @return array{0: array<string, mixed>, 1: ?int}
     */
    private static function resolveTypeAndParent(Page $page, array $form, bool $isCreate): array
    {
        if ($isCreate && is_string($form['type'] ?? null) && array_key_exists(PageForm::CREATE_SUBTYPE_PREFIX . $form['type'], $form)) {
            $form['subtype'] = $form[PageForm::CREATE_SUBTYPE_PREFIX . $form['type']];
        }

        $parentId = PageFormValues::nullableInt($form['parent_id'] ?? null);
        if (! ($page->exists && $page->is_system) && is_string($form['type'] ?? null)) {
            ['type' => $form['type'], 'parentId' => $parentId] = PageLeafType::resolve($page, $form['type'], $parentId);
            // A submitted null clears the subtype (empty strings arrive as null), so only a default is written back.
            $default = PageLeafType::defaultSubtype($page, $form['type'], $parentId, array_key_exists('subtype', $form) ? $form['subtype'] : $page->subtype);
            if ($default !== null) {
                $form['subtype'] = $default;
            }
        }

        return [$form, $parentId];
    }

    /** @param  array<string, mixed>  $form */
    private static function fill(Page $page, array $form, ?int $parentId, bool $isCreate): void
    {
        // Read before fill() overwrites it — content's tpl_id provenance
        // (see fillContent()'s docblock) is carried over from THIS, the
        // pre-save envelope, by matching block id.
        $contentBefore = $isCreate ? [] : BlockEnvelope::blocksOf($page->content);
        // Folded against the types the form was rendered for, before fill() may change them.
        $fields = $isCreate ? [] : PageEditTypeFields::fold($form, $page);

        $page->fill(PageFormAttributes::for($page, $isCreate ? PageFormAttributes::withTitleFallback($form) : $form, $parentId, $isCreate));

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
        if (! PageFormGuards::isSourceBacked($page)) {
            $page->content = self::fillContent($page, $form, $contentBefore, $isCreate);
            $page->starter_blocks = self::fillStarterBlocks($page, $form);
        }

        // Left unconditional rather than gated on ! $page->is_system: the
        // edit form never submits a 'slug' key for the root system page
        // (PageForm::generalFields() omits the field — see its docblock), and even if
        // a stray override reached here, PageSlugWriter::baseFor() ignores
        // overrides for any is_system page outright. Gating here too would
        // be redundant defense against the same already-guarded case.
        $page->pendingSlugOverrides = PageFormValues::stringMap($form['slug'] ?? null);
    }

    /**
     * PageObserver enforces invariants (system-page status lock, type/
     * parent hierarchy rules) as DomainException during save(). The UI
     * already avoids submitting a changed status/type/parent for system
     * pages (see PageFormAttributes::for()/PageFormGuards::typeAndStatus()),
     * so this is defense-in-depth for any caller that reaches save() without
     * going through those guards — without it, the exception would bubble out
     * of the form's onSubmit handler as an unhandled 500 instead of a
     * field error. The field picked mirrors exactly what the observer
     * itself inspects (dirty status vs. dirty type/parent), not the
     * exception's message text.
     */
    private static function persist(Page $page, ?int $expectedLockVersion, ?PageStatus $statusBefore, bool $isCreate): void
    {
        $statusDirty = $page->isDirty('status');
        $hierarchyDirty = $page->isDirty(['type', 'parent_id']);

        try {
            // Set only around the save, so a guard that throws earlier leaves no claim on the instance; PageObserver claims against it.
            $page->expectedLockVersion = $expectedLockVersion;
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
    }

    /**
     * The `content` envelope, computed separately from PageFormAttributes::for()'s
     * flat mass-assignment because it needs two things PageFormAttributes::for()
     * doesn't have: the page's OWN pre-fill content (for tpl_id carry-over,
     * update only) and its POST-fill type/parent_id (for starter-block
     * inheritance, create only) — $page has already been fill()ed with type/
     * parent_id/etc. by the time this runs (see save()).
     *
     * Create with no submitted content (the create-modal form has no block
     * repeater — see PageForm::fields()) inherits from StarterBlocks, else
     * copies its type's template() (TypeTemplateContent), instead of starting
     * empty; a create THAT does submit content (e.g. a
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
            $starter = app(StarterBlocks::class)->contentFor($page);

            return $starter !== [] ? $starter : app(TypeTemplateContent::class)->contentFor($page);
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
}
