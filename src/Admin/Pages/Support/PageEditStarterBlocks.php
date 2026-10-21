<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Str;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockEnvelope;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\StarterBlockSync;

/**
 * Category-only "Starter blocks" section on PageEditPage: a collapsed card
 * holding two tabs (new articles / new subcategories), each a card-framed
 * list of the starter's block elements — {id, kind}, structure only, no
 * field values (see StarterBlocks/StarterBlockSync's docblocks for why:
 * content belongs to the page, the starter only shapes it) — plus a row of
 * "+ {kind}" buttons per BlockRegistry kind and one "Apply to existing …"
 * force-sync action.
 *
 * Every mutation (add/remove/move/apply) is its own instant-save server
 * action. add/move/remove deliberately declare needs: [] instead of
 * needs: ['form'], and never read $ctx->form:
 *
 * - ActionFormRules::enclosingFormName() only returns the enclosing
 *   pageEditor form for an action that reads it (readsForm() checks
 *   needs: ['form']) — these actions sit inside that form (nested under its
 *   General tab), so declaring needs: ['form'] would make ActionController::
 *   validatedForm() run $request->validate() against the WHOLE pageEditor
 *   form's rules before the handler ever runs. A page with any currently
 *   invalid field elsewhere on the form (e.g. an empty required title on a
 *   just-added, not-yet-configured block) would then fail that validation on
 *   every starter-block click, and the client silently drops the response —
 *   every add/move/remove button on the page goes dead with no visible
 *   error. needs: [] skips that whole path (readsForm() is false, so
 *   enclosingFormName() returns null and ActionController::validatedForm()
 *   short-circuits to null) — exactly how the Apply action was already
 *   built, and why it never had this bug.
 * - With no validated form, save() has nothing safe to hand PageForm::save()
 *   — calling it with an empty/partial form would blank out every other
 *   field on the page. Instead save() writes starter_blocks directly via a
 *   raw query-builder update(), which also sidesteps PageObserver's
 *   lock_version bump: a real Eloquent save() on any dirty attribute bumps
 *   lock_version, which would make the user's already-open edit form's next
 *   "Save" bounce off PageFormGuards::lockVersion() as a false conflict even
 *   though nothing they were editing actually changed.
 *
 * Action names are suffixed by $type (article/category) so the two tabs'
 * actions never collide.
 *
 * Absent entirely for article/page (StarterBlocks::contentFor() is the only
 * consumer of these starters for anything other than a category, and it
 * reads them off the ancestor chain, never off the page being edited).
 */
final class PageEditStarterBlocks
{
    /** @return list<Node> */
    public static function build(S $s, Page $page): array
    {
        if ($page->type !== 'category' || ! $page->exists) {
            return [];
        }

        return [
            $s->section([
                'title' => __('tbtop-cms::pages.starter_blocks.section_title'),
                'description' => __('tbtop-cms::pages.starter_blocks.section_hint'),
                'collapsible' => true,
                'collapsed' => true,
            ], [
                $s->tabs([
                    self::tab($s, $page, 'article'),
                    self::tab($s, $page, 'category'),
                ]),
            ]),
        ];
    }

    /** @return array{label: string, body: mixed} */
    private static function tab(S $s, Page $page, string $type): array
    {
        $registry = app(BlockRegistry::class);
        $elements = self::starterOf($page, $type);

        return [
            'label' => __("tbtop-cms::pages.starter_blocks.tabs.{$type}"),
            'body' => $s->stack([
                $s->section(['variant' => 'card'], [
                    $s->stack($elements === []
                        ? [$s->displayText(__('tbtop-cms::pages.starter_blocks.empty'))->variant('muted')]
                        : self::rows($s, $page, $type, $elements, $registry)),
                ]),
                BlockPicker::build(
                    $s,
                    $registry->forPlacement($type),
                    static fn (string $kind): ActionBuilder => self::addAction($s, $page, $type, $kind),
                ),
                $s->flex([self::applyAction($s, $page, $type), $s->spacer()]),
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return list<Node>
     */
    private static function rows(S $s, Page $page, string $type, array $elements, BlockRegistry $registry): array
    {
        $last = count($elements) - 1;
        $rows = [];

        foreach ($elements as $index => $element) {
            $id = (string) ($element['id'] ?? '');
            $kind = (string) ($element['kind'] ?? '');

            $rows[] = $s->flex([
                $s->displayText(BlockPicker::label($registry, $kind)),
                $s->spacer(),
                self::moveAction($s, $page, $type, $id, 'up')->when($index > 0),
                self::moveAction($s, $page, $type, $id, 'down')->when($index < $last),
                self::removeAction($s, $page, $type, $id),
            ], align: 'center', gap: 2, variant: 'card');
        }

        return $rows;
    }

    private static function addAction(S $s, Page $page, string $type, string $kind): ActionBuilder
    {
        return $s->action("pageStarterBlockAdd_{$type}_{$kind}")
            ->label(__('tbtop-cms::pages.blocks.add'))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleAdd($page, $type, $kind),
                needs: [],
            );
    }

    private static function handleAdd(Page $page, string $type, string $kind): Effects
    {
        $page->refresh();
        $elements = self::starterOf($page, $type);
        $elements[] = ['id' => (string) Str::ulid(), 'kind' => $kind];

        return self::save($page, $type, $elements, __('tbtop-cms::pages.starter_blocks.added'));
    }

    private static function moveAction(S $s, Page $page, string $type, string $id, string $direction): ActionBuilder
    {
        $directionLabel = $direction === 'up' ? 'Up' : 'Down';

        return $s->action("pageStarterBlockMove{$directionLabel}_{$type}_{$id}")
            ->label(__("tbtop-cms::pages.starter_blocks.move_{$direction}"))
            ->icon($direction === 'up' ? 'arrow-up' : 'arrow-down')
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleMove($page, $type, $id, $direction),
                needs: [],
            );
    }

    private static function handleMove(Page $page, string $type, string $id, string $direction): Effects
    {
        $page->refresh();
        $elements = self::starterOf($page, $type);
        $index = self::indexOfId($elements, $id);
        $target = $index === null ? null : ($direction === 'up' ? $index - 1 : $index + 1);

        if ($index === null || $target === null || $target < 0 || $target >= count($elements)) {
            return Effects::make()->notify(__('tbtop-cms::pages.conflict.message'), 'error');
        }

        [$elements[$index], $elements[$target]] = [$elements[$target], $elements[$index]];

        return self::save($page, $type, $elements, __('tbtop-cms::pages.starter_blocks.reordered'));
    }

    private static function removeAction(S $s, Page $page, string $type, string $id): ActionBuilder
    {
        return $s->action("pageStarterBlockRemove_{$type}_{$id}")
            ->label(__('tbtop-cms::pages.blocks.remove'))->color('danger')->icon('trash-2')
            ->confirm(__('tbtop-cms::pages.blocks.remove_title'), __('tbtop-cms::pages.blocks.remove_confirm'))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleRemove($page, $type, $id),
                needs: [],
            );
    }

    private static function handleRemove(Page $page, string $type, string $id): Effects
    {
        $page->refresh();
        $elements = array_values(array_filter(
            self::starterOf($page, $type),
            static fn (array $element): bool => (string) ($element['id'] ?? '') !== $id,
        ));

        return self::save($page, $type, $elements, __('tbtop-cms::pages.blocks.removed'));
    }

    private static function applyAction(S $s, Page $page, string $type): ActionBuilder
    {
        $count = app(StarterBlockSync::class)->affectedCount($page, $type);

        return $s->action("pageStarterBlocksApply_{$type}")
            ->label(__("tbtop-cms::pages.starter_blocks.apply.{$type}"))
            ->when(self::starterOf($page, $type) !== [])
            ->confirm(
                __('tbtop-cms::pages.starter_blocks.apply.title'),
                $count > 0
                    ? trans_choice('tbtop-cms::pages.starter_blocks.apply.confirm', $count, ['count' => (string) $count])
                    : __('tbtop-cms::pages.starter_blocks.apply.confirm_zero'),
            )
            ->handle(fn (ActionCtx $ctx): Effects => self::handleApply($page, $type), needs: []);
    }

    private static function handleApply(Page $page, string $type): Effects
    {
        $count = app(StarterBlockSync::class)->apply($page, $type);

        return Effects::make()->notify(trans_choice(
            'tbtop-cms::pages.starter_blocks.apply.applied',
            $count,
            ['count' => (string) $count],
        ))->redirect(route('tbtop.cms.page-edit-page', ['page' => $page->getKey()]));
    }

    /**
     * Shared save tail for add/move/remove: writes the recomputed starter
     * under `starter_blocks` — and ONLY that column. No PageForm::save(): with
     * needs: [] there is no validated form to hand it (see class docblock),
     * and no other field on the page is meant to change here anyway.
     *
     * Goes around Eloquent entirely (toBase()->update(), not $page->save()):
     * an Eloquent save on this dirty attribute would fire PageObserver's
     * update path and bump lock_version, which the user's already-open edit
     * form has no way to know about — its next "Save" would then bounce off
     * PageFormGuards::lockVersion() as a false conflict, even though nothing
     * IT was editing changed. json_encode() is manual because the base query
     * builder bypasses the model's 'array' cast.
     *
     * @param  list<array<string, mixed>>  $elements
     */
    private static function save(Page $page, string $type, array $elements, string $message): Effects
    {
        $starterBlocks = [
            ...(is_array($page->starter_blocks) ? $page->starter_blocks : []),
            $type => $elements,
        ];

        Page::query()->whereKey($page->getKey())->toBase()->update([
            'starter_blocks' => json_encode($starterBlocks),
        ]);

        return Effects::make()->notify($message)
            ->redirect(route('tbtop.cms.page-edit-page', ['page' => $page->getKey()]));
    }

    /** @return list<array<string, mixed>> */
    private static function starterOf(Page $page, string $type): array
    {
        return BlockEnvelope::starterOf($page->starter_blocks, $type);
    }

    /** @param  list<array<string, mixed>>  $elements */
    private static function indexOfId(array $elements, string $id): ?int
    {
        foreach ($elements as $index => $element) {
            if ((string) ($element['id'] ?? '') === $id) {
                return $index;
            }
        }

        return null;
    }
}
