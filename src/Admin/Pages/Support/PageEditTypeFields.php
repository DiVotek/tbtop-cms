<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\TypeBlocks;

/**
 * The General tab's share of a page type's blocks (see TypeBlocks): the
 * forms of its general blocks, under the same `block_{id}_` prefix the
 * block tabs use so PageEditBlocks reads them back unchanged, and the
 * notice about rows another type left behind, with the action to drop them.
 */
final class PageEditTypeFields
{
    /** @return list<mixed> */
    public static function build(S $s, Page $page): array
    {
        $registry = app(BlockRegistry::class);
        $typeBlocks = app(TypeBlocks::class);
        $nodes = [];

        foreach (PageEditBlocks::blocks($page) as $block) {
            if (! $typeBlocks->isGeneral($block) || ! $registry->has((string) $block['kind'])) {
                continue;
            }
            $id = (string) $block['id'];
            $kind = (string) $block['kind'];
            $nodes[] = $s->section(['title' => BlockPicker::label($registry, $kind)], array_map(
                static fn ($field) => PrefixedField::rename($field, "block_{$id}_{$field->name}"),
                $registry->get($kind)->form($s),
            ));
        }

        $dormant = TypeBlocks::dormant($page->content, $page->type);
        if ($dormant !== []) {
            $nodes[] = self::dormantNotice($s, $page, $dormant);
        }

        return $nodes;
    }

    /** @param  array<string, int>  $dormant */
    private static function dormantNotice(S $s, Page $page, array $dormant): mixed
    {
        $types = app(PageTypeRegistry::class);
        $lines = [];
        foreach ($dormant as $type => $count) {
            $label = $types->has($type) ? $types->get($type)->label() : $type;
            $lines[] = '- ' . trans_choice('tbtop-cms::pages.type_blocks.dormant_line', $count, ['type' => $label, 'count' => $count]);
        }

        return $s->section([
            'title' => __('tbtop-cms::pages.type_blocks.dormant_title'),
            'description' => __('tbtop-cms::pages.type_blocks.dormant_hint'),
        ], [
            $s->markdown(implode("\n", $lines)),
            $s->flex([self::purgeAction($s, $page), $s->spacer()]),
        ]);
    }

    private static function purgeAction(S $s, Page $page): ActionBuilder
    {
        return $s->action('pageTypeBlocksPurge')
            ->label(__('tbtop-cms::pages.type_blocks.purge'))->color('danger')->outlined()
            ->confirm(__('tbtop-cms::pages.type_blocks.purge_title'), __('tbtop-cms::pages.type_blocks.purge_confirm'))
            ->withoutValidation(PageEditBlockTabs::skipsGate($page))
            ->handle(fn (ActionCtx $ctx): Effects => self::handlePurge($ctx, $page), needs: ['form']);
    }

    private static function handlePurge(ActionCtx $ctx, Page $page): Effects
    {
        $rows = array_values(array_filter(
            PageEditBlocks::toRows($ctx->form, $page),
            static fn (array $row): bool => (TypeBlocks::ownerOf($row) ?? $page->type) === $page->type,
        ));

        try {
            PageForm::save(PageEditBlockTabs::structuralForm($ctx->form, $page, $rows), $page->getKey());
        } catch (PageConflictException) {
            return Effects::make()->notify(__('tbtop-cms::pages.conflict.message'), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::pages.type_blocks.purged'))
            ->redirect(route('tbtop.cms.page-edit-page', ['page' => $page->getKey()]));
    }
}
