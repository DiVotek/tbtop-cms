<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\TypeBlock;
use Tbtop\Cms\Page\TypeBlocks;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\SharedBlock\SharedBlocks;
use Tbtop\Cms\SharedBlock\SharedBlockUsage;

/**
 * Builds one form tab per saved block on PageEditPage (fields prefixed
 * `block_{id}_*` via PrefixedField), plus a trailing "+ Add block" tab with
 * one server action per registered kind. Each tab also owns its own
 * "remove block" action.
 *
 * Tabs are built in PageEditBlocks::blocks() order, i.e. sorted by the
 * envelope's `sort` field — reordering a block's `block_{id}_sort` number
 * field and saving moves its tab on the next page load.
 */
final class PageEditBlockTabs
{
    /** @return list<array{label: string, body: mixed, badge?: string}> */
    public static function build(S $s, Page $page): array
    {
        $registry = app(BlockRegistry::class);
        $tabs = [];

        $typeBlocks = app(TypeBlocks::class);

        foreach (PageEditBlocks::blocks($page) as $block) {
            // A general type block has no tab: PageEditTypeFields puts its form on the General tab.
            if (! $typeBlocks->isGeneral($block)) {
                $tabs[] = self::blockTab($s, $page, $registry, $block);
            }
        }

        $tabs[] = self::addBlockTab($s, $page, $registry);

        return $tabs;
    }

    /**
     * Prefixed record values for every saved block, merged into the
     * pageEditor form's record() — block_{id}_{field} plus block_{id}_active.
     *
     * @return array<string, mixed>
     */
    public static function recordValues(Page $page): array
    {
        $registry = app(BlockRegistry::class);
        $values = [];

        foreach (PageEditBlocks::blocks($page) as $block) {
            $id = (string) $block['id'];
            $kind = (string) $block['kind'];
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $values["block_{$id}_active"] = (bool) ($block['active'] ?? true);
            $values["block_{$id}_sort"] = (int) $block['sort'];

            if (! $registry->has($kind)) {
                continue;
            }

            foreach ($registry->get($kind)->form(new S) as $field) {
                if (array_key_exists($field->name, $data)) {
                    $values["block_{$id}_{$field->name}"] = $data[$field->name];
                }
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{label: string, body: mixed, badge?: string}
     */
    private static function blockTab(S $s, Page $page, BlockRegistry $registry, array $block): array
    {
        $id = (string) $block['id'];
        $kind = (string) $block['kind'];
        $active = (bool) ($block['active'] ?? true);
        $shared = SharedBlocks::refOf($block);

        if ($shared !== null) {
            return self::sharedTab($s, $page, $registry, $block, $shared);
        }

        $fields = $registry->has($kind)
            ? array_map(
                static fn ($field) => PrefixedField::rename($field, "block_{$id}_{$field->name}", "block_{$id}_active"),
                $registry->get($kind)->form($s),
            )
            : [];

        // A type-owned block is part of the page type: it cannot be removed
        // (its fields would go with it) nor shared, only switched off or moved.
        $isOwned = TypeBlocks::ownerOf($block) !== null;

        $body = $s->stack([
            ...$fields,
            $s->flex([
                $s->boolean("block_{$id}_active")->label(__('tbtop-cms::pages.blocks.active')),
                $s->number("block_{$id}_sort")->label(__('tbtop-cms::pages.blocks.sort')),
            ], gap: 4, align: 'end'),
            ...($isOwned ? [] : [$s->flex([
                self::removeAction($s, $page, $id),
                $s->spacer(),
                ...($registry->has($kind) ? [self::shareAction($s, $page, $registry, $id, $kind)] : []),
            ])]),
        ]);

        $tab = ['label' => BlockPicker::label($registry, $kind), 'body' => $body];
        if (! $active) {
            $tab['badge'] = '·';
        }

        return $tab;
    }

    /**
     * The tab of a reference: no fields — the content lives on the shared
     * block — but a link to edit it there, and "detach", which copies the
     * shared content into this page so the page can diverge.
     *
     * @param  array<string, mixed>  $block
     * @return array{label: string, body: mixed, badge?: string}
     */
    private static function sharedTab(S $s, Page $page, BlockRegistry $registry, array $block, int $shared): array
    {
        $id = (string) $block['id'];
        $kind = (string) $block['kind'];
        // The row's kind decides the component (as in the resolver): a target
        // of another kind is as unusable as a deleted one, and must not be
        // detached into this row.
        $source = SharedBlock::query()->find($shared);
        if ($source instanceof SharedBlock && $source->kind !== $kind) {
            $source = null;
        }
        $label = BlockPicker::label($registry, $kind);

        $info = $source instanceof SharedBlock
            ? [
                $s->displayText(__('tbtop-cms::shared-blocks.reference.info', ['name' => $source->name]))->variant('muted'),
                $s->flex([
                    $s->action("pageBlockEditShared_{$id}")->label(__('tbtop-cms::shared-blocks.reference.edit_source'))
                        ->url(route('tbtop.cms.shared-block-edit-page', ['sharedBlock' => $source->getKey()])),
                    self::detachAction($s, $page, $id, $source),
                ], gap: 2),
            ]
            : [$s->displayText(__('tbtop-cms::shared-blocks.reference.missing', ['id' => $shared]))->variant('muted')];

        $body = $s->stack([
            ...$info,
            $s->flex([
                $s->boolean("block_{$id}_active")->label(__('tbtop-cms::pages.blocks.active')),
                $s->number("block_{$id}_sort")->label(__('tbtop-cms::pages.blocks.sort')),
            ], gap: 4, align: 'end'),
            $s->flex([self::removeAction($s, $page, $id), $s->spacer()]),
        ]);

        $tab = ['label' => $label . ' · ' . ($source instanceof SharedBlock ? $source->name : "#{$shared}"), 'body' => $body];
        if (! (bool) ($block['active'] ?? true)) {
            $tab['badge'] = '·';
        }

        return $tab;
    }

    private static function detachAction(S $s, Page $page, string $id, SharedBlock $source): ActionBuilder
    {
        return $s->action("pageBlockDetach_{$id}")
            ->label(__('tbtop-cms::shared-blocks.reference.detach'))
            ->confirm(__('tbtop-cms::shared-blocks.reference.detach_title'), __('tbtop-cms::shared-blocks.reference.detach_confirm', ['name' => $source->name]))
            ->withoutValidation(self::skipsGate($page))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleDetach($ctx, $page, $id, $source),
                needs: ['form'],
            );
    }

    /** The row keeps its id, order and active flag; only the pointer becomes a copy of the content. */
    private static function handleDetach(ActionCtx $ctx, Page $page, string $id, SharedBlock $source): Effects
    {
        $rows = [];
        foreach (PageEditBlocks::toRows($ctx->form, $page) as $row) {
            if ((string) $row['id'] === $id) {
                unset($row['shared']);
                $row = [...$row, ...$source->data];
            }
            $rows[] = $row;
        }

        try {
            PageForm::save(self::structuralForm($ctx->form, $page, $rows), $page->getKey());
        } catch (PageConflictException) {
            return Effects::make()->notify(__('tbtop-cms::pages.conflict.message'), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::shared-blocks.reference.detached'))
            ->redirect(route('tbtop.cms.page-edit-page', ['page' => $page->getKey()]));
    }

    /**
     * Turns this inline block into a shared one in a click: the content as
     * it is in the form right now — unsaved edits included — becomes a new
     * shared block and the row a reference to it. The name is derived
     * (block label — page name) because a modal with its own form would
     * hand the handler the modal's values, not the page's; it is renamed
     * on the shared block's edit page, linked from the reference tab.
     */
    private static function shareAction(S $s, Page $page, BlockRegistry $registry, string $id, string $kind): ActionBuilder
    {
        return $s->action("pageBlockShare_{$id}")
            ->label(__('tbtop-cms::shared-blocks.share.action'))->outlined()
            ->confirm(__('tbtop-cms::shared-blocks.share.title'), __('tbtop-cms::shared-blocks.share.confirm'))
            ->withoutValidation(self::skipsGate($page))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleShare($ctx, $page, $registry, $id, $kind),
                needs: ['form'],
            );
    }

    private static function handleShare(ActionCtx $ctx, Page $page, BlockRegistry $registry, string $id, string $kind): Effects
    {
        $rows = [];
        $shared = null;
        foreach (PageEditBlocks::toRows($ctx->form, $page) as $row) {
            if ((string) $row['id'] === $id) {
                $shared = SharedBlockForm::createFromRow(
                    self::sharedName(BlockPicker::label($registry, $kind), $page),
                    $kind,
                    Arr::except($row, ['id', 'kind', 'active', 'sort', 'shared']),
                );
                $row = [...Arr::only($row, ['id', 'kind', 'active', 'sort']), 'shared' => (int) $shared->getKey()];
            }
            $rows[] = $row;
        }
        if (! $shared instanceof SharedBlock) {
            return Effects::make()->notify(__('tbtop-cms::shared-blocks.share.missing'), 'error');
        }

        try {
            PageForm::save(self::structuralForm($ctx->form, $page, $rows), $page->getKey());
        } catch (PageConflictException) {
            $shared->delete();

            return Effects::make()->notify(__('tbtop-cms::pages.conflict.message'), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::shared-blocks.share.done', ['name' => $shared->name]))
            ->redirect(route('tbtop.cms.page-edit-page', ['page' => $page->getKey()]));
    }

    /** "Label — Page", with " 2", " 3"… while taken, so two shares from the same page stay apart. */
    private static function sharedName(string $label, Page $page): string
    {
        $pageName = $page->getTranslation('name', (string) app()->getLocale(), true);
        $base = $label . ' — ' . (is_string($pageName) && $pageName !== '' ? $pageName : '#' . $page->getKey());
        $name = $base;
        for ($i = 2; SharedBlock::query()->where('name', $name)->exists(); $i++) {
            $name = "{$base} {$i}";
        }

        return $name;
    }

    private static function removeAction(S $s, Page $page, string $id): ActionBuilder
    {
        return $s->action("pageBlockRemove_{$id}")
            ->label(__('tbtop-cms::pages.blocks.remove'))->color('danger')
            ->confirm(__('tbtop-cms::pages.blocks.remove_title'), __('tbtop-cms::pages.blocks.remove_confirm'))
            ->withoutValidation(self::skipsGate($page))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleRemove($ctx, $page, $id),
                needs: ['form'],
            );
    }

    /**
     * Add/remove persist the whole pageEditor form outside the explicit Save,
     * so on a published page they run the form's rules first — otherwise a
     * half-edited required field would go live with the structural change.
     * A draft cannot leak, so there the gate stays off (keys are still
     * filtered to the form's schema). Inactive blocks never trip the gate:
     * PrefixedField::rename() conditions their `required` on the active flag.
     */
    public static function skipsGate(Page $page): bool
    {
        return $page->status !== PageStatus::Published;
    }

    /**
     * A structural action must not publish or unpublish: the gate above is
     * decided by the persisted status, so a pending status change in the form
     * waits for the explicit (validated) Save.
     *
     * @param  array<string, mixed>  $form
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function structuralForm(array $form, Page $page, array $rows): array
    {
        return [...$form, 'content' => $rows, 'status' => $page->status->value];
    }

    private static function handleRemove(ActionCtx $ctx, Page $page, string $id): Effects
    {
        $rows = array_values(array_filter(
            PageEditBlocks::toRows($ctx->form, $page),
            static fn (array $row): bool => (string) $row['id'] !== $id || TypeBlocks::ownerOf($row) !== null,
        ));

        // Same stale-form guard as PageEditPage::save() — this action saves
        // outside the normal form submit cycle, but it carries the same
        // $ctx->form (including lock_version) and reaches the same
        // PageForm::save(), so it needs the same non-destructive handling of
        // a conflict: error notify, no redirect, block NOT removed.
        try {
            PageForm::save(self::structuralForm($ctx->form, $page, $rows), $page->getKey());
        } catch (PageConflictException) {
            return Effects::make()->notify(__('tbtop-cms::pages.conflict.message'), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::pages.blocks.removed'))
            ->redirect(route('tbtop.cms.page-edit-page', ['page' => $page->getKey()]));
    }

    /** @return array{label: string, body: mixed} */
    private static function addBlockTab(S $s, Page $page, BlockRegistry $registry): array
    {
        $shared = SharedBlock::query()->orderBy('name')->get()->groupBy('kind');
        $usage = $shared->isEmpty() ? [] : SharedBlockUsage::index();

        return [
            'label' => __('tbtop-cms::pages.tabs_edit.add_block'),
            'body' => BlockPicker::build(
                $s,
                self::offered($registry, $page),
                static fn (string $kind): ActionBuilder => $shared->has($kind)
                    ? self::addChoiceAction($s, $page, $registry, $kind, $shared->get($kind)->all(), $usage)
                    : self::addAction($s, $page, $kind),
            ),
        ];
    }

    /**
     * A kind with shared blocks asks which to add: one of them by reference,
     * or a fresh inline one. Kinds without shared blocks add inline at once.
     *
     * @param  list<SharedBlock>  $blocks
     * @param  array<int, list<Page>>  $usage
     */
    private static function addChoiceAction(S $s, Page $page, BlockRegistry $registry, string $kind, array $blocks, array $usage): ActionBuilder
    {
        $rows = [];
        foreach ($blocks as $shared) {
            $rows[] = $s->flex([
                $s->flex([
                    $s->displayText($shared->name)->variant('subheading'),
                    $s->displayText(trans_choice('tbtop-cms::shared-blocks.usage_count', count($usage[(int) $shared->getKey()] ?? [])))->variant('muted'),
                ], direction: 'col', gap: 0),
                $s->spacer(),
                self::addSharedAction($s, $page, $shared),
            ], align: 'center', gap: 2, variant: 'card');
        }

        $body = $s->stack([
            $s->displayText(__('tbtop-cms::shared-blocks.choice.description'))->variant('muted'),
            $s->stack($rows, ['gap' => 2]),
            $s->flex([$s->spacer(), self::addAction($s, $page, $kind)->label(__('tbtop-cms::shared-blocks.choice.add_inline'))->outlined()]),
        ]);

        return $s->action("pageBlockChoose_{$kind}")
            ->label(__('tbtop-cms::pages.blocks.add'))
            ->modal(__('tbtop-cms::shared-blocks.choice.title', ['label' => BlockPicker::label($registry, $kind)]), $body);
    }

    private static function addSharedAction(S $s, Page $page, SharedBlock $shared): ActionBuilder
    {
        return $s->action('pageBlockAddShared_' . $shared->getKey())
            ->label(__('tbtop-cms::pages.blocks.add'))
            ->withoutValidation(self::skipsGate($page))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleAdd($ctx, $page, $shared->kind, (int) $shared->getKey()),
                needs: ['form'],
            );
    }

    private static function addAction(S $s, Page $page, string $kind): ActionBuilder
    {
        return $s->action("pageBlockAdd_{$kind}")
            ->label(__('tbtop-cms::pages.blocks.add'))
            ->withoutValidation(self::skipsGate($page))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleAdd($ctx, $page, $kind),
                needs: ['form'],
            );
    }

    private static function handleAdd(ActionCtx $ctx, Page $page, string $kind, ?int $shared = null): Effects
    {
        $existingRows = PageEditBlocks::toRows($ctx->form, $page);
        // Persisted inactive: handleAdd saves outside the normal page Save
        // cycle (see class docblock), so an empty, unconfigured block must
        // not go live on a published page the moment it's added. The editor
        // activates it explicitly once it's configured. A shared reference is
        // configured already, but the same rule keeps "add" from publishing.
        $newRow = ['id' => (string) Str::ulid(), 'kind' => $kind, 'active' => false, 'sort' => self::nextSort($existingRows)];
        if ($shared !== null) {
            $newRow['shared'] = $shared;
        }
        $rows = [...$existingRows, $newRow];

        // See handleRemove()'s matching comment: same conflict handling.
        try {
            PageForm::save(self::structuralForm($ctx->form, $page, $rows), $page->getKey());
        } catch (PageConflictException) {
            return Effects::make()->notify(__('tbtop-cms::pages.conflict.message'), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::pages.blocks.added'))
            ->redirect(route('tbtop.cms.page-edit-page', ['page' => $page->getKey()]));
    }

    /**
     * The kinds "+ Add block" offers: the type's placement minus the general
     * kinds of every type (those live on a General tab, never in the flow)
     * and minus the kinds this page's type already carries.
     *
     * @return array<string, Block>
     */
    private static function offered(BlockRegistry $registry, Page $page): array
    {
        $typeBlocks = app(TypeBlocks::class);
        $excluded = [
            ...$typeBlocks->generalKinds(),
            ...array_map(static fn (TypeBlock $block): string => $block->kind, $typeBlocks->declared($page->type)),
        ];

        return array_filter(
            $registry->forPlacement($page->type),
            static fn (Block $block): bool => ! in_array($block->kind(), $excluded, true),
        );
    }

    /**
     * New blocks are appended, not inserted — max(existing sort) + 1 puts
     * them last regardless of what sort values the existing rows carry.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function nextSort(array $rows): int
    {
        $sorts = array_map(static fn (array $row): int => (int) ($row['sort'] ?? 0), $rows);

        return $sorts === [] ? 0 : max($sorts) + 1;
    }
}
