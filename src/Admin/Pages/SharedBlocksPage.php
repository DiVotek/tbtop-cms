<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\BlockPicker;
use Tbtop\Cms\Admin\Pages\Support\BlockShowcaseHtml;
use Tbtop\Cms\Admin\Pages\Support\SharedBlockForm;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\SharedBlock\Events\SharedBlockDeleted;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\SharedBlock\SharedBlockUsage;

/**
 * Blocks edited once and placed on pages by reference, shown as the same
 * card grid as BlocksPage. Creating one asks for a name and a kind; the
 * content is edited on SharedBlockEditPage. A block still placed on a page
 * cannot be deleted — the pages would render a "missing shared block"
 * placeholder.
 */
class SharedBlocksPage extends AdminPage
{
    public static function path(): string
    {
        return 'shared-blocks';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'design',
            'label' => __('tbtop-cms::nav.items.shared_blocks'),
            'order' => 45,
            'icon' => 'copy',
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.design');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::shared-blocks.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::shared-blocks.subtitle');
    }

    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.design')],
            ['label' => __('tbtop-cms::nav.items.shared_blocks')],
        ];
    }

    #[\Override]
    public function headerActions(S $s): array
    {
        return [$this->createAction($s)];
    }

    public function view(S $s): Node
    {
        $blocks = SharedBlock::query()->orderBy('name')->get();
        if ($blocks->isEmpty()) {
            return $s->stack([
                $s->displayAlert(__('tbtop-cms::shared-blocks.empty.description'))
                    ->title(__('tbtop-cms::shared-blocks.empty.title')),
            ]);
        }

        $usage = SharedBlockUsage::index();
        $registry = app(BlockRegistry::class);
        $card = fn (SharedBlock $block): Node => $this->card($s, $registry, $block, count($usage[(int) $block->getKey()] ?? []));
        $grid = fn (iterable $items): Node => $s->grid(['cols' => ['sm' => 2, 'md' => 3, 'lg' => 4], 'gap' => 4], array_map($card, [...$items]));

        // One tab per kind is the type filter: a site with two hundred FAQ
        // entries still finds its three CTAs without scrolling past them.
        $byKind = $blocks->groupBy('kind')->sortKeys();
        if ($byKind->count() < 2) {
            return $s->stack([$grid($blocks)]);
        }

        $tabs = [['label' => __('tbtop-cms::shared-blocks.tabs.all'), 'badge' => (string) $blocks->count(), 'body' => $grid($blocks)]];
        foreach ($byKind as $kind => $group) {
            $tabs[] = ['label' => BlockPicker::label($registry, (string) $kind), 'badge' => (string) $group->count(), 'body' => $grid($group)];
        }

        return $s->stack([$s->tabs($tabs)]);
    }

    /** Same card as BlocksPage, so both block surfaces read alike. */
    private function card(S $s, BlockRegistry $registry, SharedBlock $block, int $pages): Node
    {
        $kind = $block->kind;
        $label = BlockPicker::label($registry, $kind);
        $category = $registry->has($kind) ? $registry->get($kind)->category() : null;
        $category ??= __('tbtop-cms::blocks_showcase.uncategorized');

        // Delete sits over the preview, shown on hover: it exists only for a
        // block nothing places, so it must not claim room on every card.
        $preview = [$s->displayHtml(BlockShowcaseHtml::placeholder($kind, $label))];
        if ($pages === 0) {
            $preview[] = $s->stack([$this->deleteAction($s, $block)], [
                'class' => 'absolute top-2 right-2 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity',
            ]);
        }

        return $s->stack([
            $s->stack($preview, ['class' => 'relative group gap-0']),
            $s->stack([
                $s->displayText($block->name)->variant('subheading'),
                $s->flex([
                    $s->displayText($label)->variant('muted'),
                    $s->displayValue($category)->badge([$category => ['color' => Color::Info]]),
                ], gap: 2, align: 'center'),
                $s->flex([
                    $s->displayText(trans_choice('tbtop-cms::shared-blocks.usage_count', $pages))->variant('muted'),
                    $this->editAction($s, $block),
                ], justify: 'between', align: 'center'),
            ], ['class' => 'p-3 gap-2']),
        ], ['class' => 'rounded-lg border bg-card overflow-hidden gap-0']);
    }

    private function createAction(S $s): ActionBuilder
    {
        $cancel = $s->action('sharedBlockCreateCancel')->label(__('tbtop-cms::common.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $create = $s->action('sharedBlockCreateSave')->label(__('tbtop-cms::shared-blocks.actions.create'))->color('primary')
            ->handle($this->create(...), needs: ['form']);

        $form = $s->form('sharedBlockCreate', [
            ...SharedBlockForm::createFields($s),
            $s->flex([$s->spacer(), $cancel, $create], justify: 'end'),
        ]);

        return $s->action('sharedBlockCreate')->label(__('tbtop-cms::shared-blocks.actions.new'))
            ->icon('plus')->color('primary')
            ->modal(__('tbtop-cms::shared-blocks.actions.create_title'), $form);
    }

    private function create(ActionCtx $ctx): Effects
    {
        try {
            $block = SharedBlockForm::create($ctx->form);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return Effects::make()->haltModal(is_string($first) ? $first : $e->getMessage());
        }

        return Effects::make()->closeModal()->redirect(self::editUrl($block));
    }

    private function editAction(S $s, SharedBlock $block): ActionBuilder
    {
        // label('') → icon-only; the tooltip carries the accessible name.
        return $s->action('sharedBlockEdit_' . $block->getKey())
            ->label('')->link()->icon('pencil')
            ->tooltip(__('tbtop-cms::shared-blocks.actions.edit'))
            ->url(self::editUrl($block));
    }

    /**
     * Built only while nothing places the block — the usage line says why the
     * button is absent — and the handler checks again: a placement made
     * between render and click must not leave a dangling reference.
     */
    private function deleteAction(S $s, SharedBlock $block): ActionBuilder
    {
        $id = (int) $block->getKey();

        return $s->action('sharedBlockDelete_' . $id)
            ->label('')->icon('trash')->color('danger')->size('sm')
            ->tooltip(__('tbtop-cms::shared-blocks.actions.delete'))
            ->confirm(__('tbtop-cms::shared-blocks.actions.delete_title'), __('tbtop-cms::shared-blocks.actions.delete_confirm'))
            ->handle(static function () use ($id): Effects {
                $block = SharedBlock::query()->find($id);
                if (! $block instanceof SharedBlock) {
                    return Effects::make()->redirect(route('tbtop.cms.shared-blocks-page'));
                }

                $inUse = count(SharedBlockUsage::using($id));
                if ($inUse > 0) {
                    return Effects::make()->notify(trans_choice('tbtop-cms::shared-blocks.actions.delete_in_use', $inUse), 'error');
                }

                $block->delete();
                event(new SharedBlockDeleted($block));

                return Effects::make()->notify(__('tbtop-cms::shared-blocks.actions.deleted'))
                    ->redirect(route('tbtop.cms.shared-blocks-page'));
            });
    }

    public static function editUrl(SharedBlock $block): string
    {
        return route('tbtop.cms.shared-block-edit-page', ['sharedBlock' => $block->getKey()]);
    }
}
