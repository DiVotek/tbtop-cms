<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Layout\Models\Layout;

/**
 * Builds one Layouts-page card for a slot: header, its blocks in render
 * order, and a footer meta + edit-action row. Everything here is read-only
 * introspection of a Layout row — saving happens through LayoutForm/LayoutEditPage.
 */
class LayoutSlotCard
{
    public static function build(S $s, string $slot): Node
    {
        $layout = Layout::query()->where('slot', $slot)->first();

        return $s->stack([
            $s->displayText(LayoutForm::label($slot))->variant('subheading'),
            $s->displayText(__('tbtop-cms::layout.slots_description.' . $slot))->variant('muted'),
            self::body($s, $layout),
            self::footer($s, $slot, $layout),
        ], ['class' => 'rounded-lg border bg-card p-4 gap-3']);
    }

    private static function body(S $s, ?Layout $layout): Node
    {
        $content = self::content($layout);

        if ($content === []) {
            return $s->stack([$s->displayText(__('tbtop-cms::layout.empty'))->variant('muted')]);
        }

        $registry = app(BlockRegistry::class);

        // content[] order IS render order on the site (position, not a
        // separate 'sort' field, drives placement) — iterate as stored,
        // never re-sort, or the card would misrepresent the live page.
        $rows = [];
        foreach ($content as $i => $entry) {
            $rows[] = self::blockRow($s, $registry, $i, $entry);
        }

        return $s->stack($rows, ['class' => 'gap-1']);
    }

    /** @param array<string, mixed> $entry */
    private static function blockRow(S $s, BlockRegistry $registry, int $i, array $entry): Node
    {
        $kind = (string) ($entry['kind'] ?? '');
        $label = $registry->has($kind) ? $registry->get($kind)->label() : $kind;
        $isActive = ($entry['active'] ?? true) !== false;

        $text = $s->displayText(($i + 1) . '. ' . $label)->variant($isActive ? 'body' : 'muted');

        if ($isActive) {
            return $s->stack([$text]);
        }

        return $s->flex([
            $text,
            $s->displayValue(__('tbtop-cms::layout.blocks.inactive_badge'))
                ->badge([__('tbtop-cms::layout.blocks.inactive_badge') => Color::Gray]),
        ], align: 'center');
    }

    private static function footer(S $s, string $slot, ?Layout $layout): Node
    {
        $editAction = $s->action('edit-' . $slot)->label(__('tbtop-cms::layout.actions.edit'))->visit(
            route('tbtop.cms.layout-edit-page', ['slot' => $slot]),
        );

        $content = self::content($layout);

        if ($layout === null || $content === []) {
            return $s->flex([$editAction], justify: 'end', align: 'center');
        }

        $meta = trans_choice('tbtop-cms::layout.blocks_count', count($content), ['count' => (string) count($content)])
            . ' · '
            . __('tbtop-cms::layout.updated_at', ['time' => optional($layout->updated_at)->diffForHumans() ?? '']);

        return $s->flex([
            $s->displayText($meta)->variant('muted'),
            $editAction,
        ], justify: 'between', align: 'center');
    }

    /** @return list<array<string, mixed>> */
    private static function content(?Layout $layout): array
    {
        return $layout === null ? [] : ($layout->content ?? []);
    }
}
