<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Str;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;

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

        foreach (PageEditBlocks::blocks($page) as $block) {
            $tabs[] = self::blockTab($s, $page, $registry, $block);
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

        $fields = $registry->has($kind)
            ? array_map(
                static fn ($field) => PrefixedField::rename($field, "block_{$id}_{$field->name}", "block_{$id}_active"),
                $registry->get($kind)->form($s),
            )
            : [];

        $body = $s->stack([
            ...$fields,
            $s->flex([
                $s->boolean("block_{$id}_active")->label(__('tbtop-cms::pages.blocks.active')),
                $s->number("block_{$id}_sort")->label(__('tbtop-cms::pages.blocks.sort')),
            ], gap: 4, align: 'end'),
            $s->flex([self::removeAction($s, $page, $id), $s->spacer()]),
        ]);

        $tab = ['label' => $kind, 'body' => $body];
        if (! $active) {
            $tab['badge'] = '·';
        }

        return $tab;
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
    private static function skipsGate(Page $page): bool
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
    private static function structuralForm(array $form, Page $page, array $rows): array
    {
        return [...$form, 'content' => $rows, 'status' => $page->status->value];
    }

    private static function handleRemove(ActionCtx $ctx, Page $page, string $id): Effects
    {
        $rows = array_values(array_filter(
            PageEditBlocks::toRows($ctx->form, $page),
            static fn (array $row): bool => (string) $row['id'] !== $id,
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
        $actions = [];
        foreach (array_keys($registry->forPlacement($page->type)) as $kind) {
            $actions[] = self::addAction($s, $page, $kind);
        }

        return [
            'label' => __('tbtop-cms::pages.tabs_edit.add_block'),
            'body' => $s->stack([$s->actionsRow($actions)]),
        ];
    }

    private static function addAction(S $s, Page $page, string $kind): ActionBuilder
    {
        return $s->action("pageBlockAdd_{$kind}")
            ->label(__('tbtop-cms::pages.blocks.add', ['kind' => $kind]))
            ->withoutValidation(self::skipsGate($page))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleAdd($ctx, $page, $kind),
                needs: ['form'],
            );
    }

    private static function handleAdd(ActionCtx $ctx, Page $page, string $kind): Effects
    {
        $existingRows = PageEditBlocks::toRows($ctx->form, $page);
        // Persisted inactive: handleAdd saves outside the normal page Save
        // cycle (see class docblock), so an empty, unconfigured block must
        // not go live on a published page the moment it's added. The editor
        // activates it explicitly once it's configured.
        $newRow = ['id' => (string) Str::ulid(), 'kind' => $kind, 'active' => false, 'sort' => self::nextSort($existingRows)];
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
