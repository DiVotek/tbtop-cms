<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Str;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\Placement;
use Tbtop\Cms\Layout\Models\Layout;

/**
 * Builds one form tab per saved block on LayoutEditPage (fields prefixed
 * `block_{id}_*` via PrefixedField), plus a trailing "+ Add block" tab with
 * one server action per registered kind. Each tab also owns its own
 * "remove block" action.
 *
 * Deliberately mirrors PageEditBlockTabs rather than extracting a shared
 * base — see LayoutEditBlocks's docblock and the commit message for why.
 * Two behavioral differences from the page editor, both because a layout has
 * no draft/published or lock_version concept:
 *  - the validation gate always runs (pages skip it for drafts only);
 *  - structural actions never need conflict handling (no lock_version to
 *    stale-check), so add/remove just call LayoutForm::save() directly.
 *
 * Tabs are built in LayoutEditBlocks::blocks() order, i.e. sorted by the
 * envelope's `sort` field — reordering a block's `block_{id}_sort` number
 * field and saving moves its tab on the next page load.
 */
final class LayoutEditBlockTabs
{
    /** @return list<array{label: string, body: mixed, badge?: string}> */
    public static function build(S $s, string $slot, ?Layout $layout): array
    {
        $registry = app(BlockRegistry::class);
        $tabs = [];

        foreach (LayoutEditBlocks::blocks($layout) as $block) {
            $tabs[] = self::blockTab($s, $slot, $registry, $block);
        }

        $tabs[] = self::addBlockTab($s, $slot, $registry);

        return $tabs;
    }

    /**
     * Prefixed record values for every saved block, merged into the
     * layoutEditor form's record() — block_{id}_{field} plus block_{id}_active.
     *
     * @return array<string, mixed>
     */
    public static function recordValues(?Layout $layout): array
    {
        $registry = app(BlockRegistry::class);
        $values = [];

        foreach (LayoutEditBlocks::blocks($layout) as $block) {
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
    private static function blockTab(S $s, string $slot, BlockRegistry $registry, array $block): array
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
            $s->flex([self::removeAction($s, $slot, $id), $s->spacer()]),
        ]);

        $tab = ['label' => $registry->has($kind) ? $registry->get($kind)->label() : $kind, 'body' => $body];
        if (! $active) {
            $tab['badge'] = '·';
        }

        return $tab;
    }

    private static function removeAction(S $s, string $slot, string $id): ActionBuilder
    {
        return $s->action("layoutBlockRemove_{$id}")
            ->label(__('tbtop-cms::pages.blocks.remove'))->color('danger')
            ->confirm(__('tbtop-cms::pages.blocks.remove_title'), __('tbtop-cms::pages.blocks.remove_confirm'))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleRemove($ctx, $slot, $id),
                needs: ['form'],
            );
    }

    private static function handleRemove(ActionCtx $ctx, string $slot, string $id): Effects
    {
        $layout = self::layoutFor($slot);
        $rows = array_values(array_filter(
            LayoutEditBlocks::toRows($ctx->form, $layout),
            static fn (array $row): bool => (string) $row['id'] !== $id,
        ));

        LayoutForm::save([...$ctx->form, 'content' => $rows], $slot);

        return Effects::make()->notify(__('tbtop-cms::pages.blocks.removed'))
            ->redirect(route('tbtop.cms.layout-edit-page', ['slot' => $slot]));
    }

    /** @return array{label: string, body: mixed} */
    private static function addBlockTab(S $s, string $slot, BlockRegistry $registry): array
    {
        $actions = [];
        foreach (array_keys($registry->forPlacement(Placement::LAYOUT)) as $kind) {
            $actions[] = self::addAction($s, $slot, $kind);
        }

        return [
            'label' => __('tbtop-cms::pages.tabs_edit.add_block'),
            'body' => $s->stack([$s->actionsRow($actions)]),
        ];
    }

    private static function addAction(S $s, string $slot, string $kind): ActionBuilder
    {
        return $s->action("layoutBlockAdd_{$kind}")
            ->label(__('tbtop-cms::pages.blocks.add', ['kind' => $kind]))
            ->handle(
                fn (ActionCtx $ctx): Effects => self::handleAdd($ctx, $slot, $kind),
                needs: ['form'],
            );
    }

    private static function handleAdd(ActionCtx $ctx, string $slot, string $kind): Effects
    {
        $layout = self::layoutFor($slot);
        $existingRows = LayoutEditBlocks::toRows($ctx->form, $layout);
        // Persisted inactive: an empty, unconfigured block must not go live
        // the instant it's added — a layout has no draft state to hide
        // behind, so this is the only thing keeping it off the live site
        // until the editor fills it in and activates it explicitly.
        $newRow = ['id' => (string) Str::ulid(), 'kind' => $kind, 'active' => false, 'sort' => self::nextSort($existingRows)];
        $rows = [...$existingRows, $newRow];

        LayoutForm::save([...$ctx->form, 'content' => $rows], $slot);

        return Effects::make()->notify(__('tbtop-cms::pages.blocks.added'))
            ->redirect(route('tbtop.cms.layout-edit-page', ['slot' => $slot]));
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

    private static function layoutFor(string $slot): ?Layout
    {
        return Layout::query()->where('slot', $slot)->first();
    }
}
