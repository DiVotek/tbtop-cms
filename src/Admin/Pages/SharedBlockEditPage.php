<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\BlockPicker;
use Tbtop\Cms\Admin\Pages\Support\SharedBlockForm;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EditFooter;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Page\Models\Page as CmsPage;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\SharedBlock\SharedBlockUsage;

class SharedBlockEditPage extends Page
{
    public static function path(): string
    {
        return 'shared-blocks/{sharedBlock}/edit';
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.design');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::shared-blocks.edit_title', ['name' => $this->block()->name]);
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return BlockPicker::label(app(BlockRegistry::class), $this->block()->kind);
    }

    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.design')],
            ['label' => __('tbtop-cms::nav.items.shared_blocks'), 'url' => $this->listUrl()],
            ['label' => $this->block()->name],
        ];
    }

    public function view(S $s): Node
    {
        $block = $this->block();
        $footer = EditFooter::build($s, $this->listUrl(), $this->saveAction($s));

        $form = $s->form('sharedBlockEditor', [
            $s->text('name')->label(__('tbtop-cms::shared-blocks.fields.name'))->required(),
            ...SharedBlockForm::dataFields($s, $block),
            $this->usage($s, $block),
            $footer,
        ])
            ->record(SharedBlockForm::recordValues($block))
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    /** Where the block is placed: an edit here changes every page listed. */
    private function usage(S $s, SharedBlock $block): Node
    {
        $pages = SharedBlockUsage::pagesUsing((int) $block->getKey());
        $hasEdit = Route::has('tbtop.cms.page-edit-page');

        $rows = [$s->displayText(trans_choice('tbtop-cms::shared-blocks.usage_count', count($pages)))->variant('muted')];
        foreach ($pages as $page) {
            $name = $this->pageName($page);
            $rows[] = $hasEdit
                ? $s->flex([
                    $s->action('sharedBlockUsage_' . $page->getKey())->label($name)
                        ->url(route('tbtop.cms.page-edit-page', ['page' => $page->getKey()])),
                    $s->spacer(),
                ])
                : $s->displayText($name);
        }

        return $s->section(['title' => __('tbtop-cms::shared-blocks.usage_title'), 'variant' => 'plain'], [$s->stack($rows, ['gap' => 1])]);
    }

    private function pageName(CmsPage $page): string
    {
        $name = $page->getTranslation('name', (string) app()->getLocale(), true);

        return is_string($name) && $name !== '' ? $name : '#' . $page->getKey();
    }

    private function saveAction(S $s): ActionBuilder
    {
        return $s->action('save')->label(__('tbtop-cms::shared-blocks.actions.save'))
            ->color('primary')->keybinding('mod+s')->submit('sharedBlockEditor');
    }

    private function save(ActionCtx $ctx): Effects
    {
        try {
            SharedBlockForm::update($ctx->form, $this->block());
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return Effects::make()->notify(is_string($first) ? $first : $e->getMessage(), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::shared-blocks.actions.saved'));
    }

    private function block(): SharedBlock
    {
        $id = request()->route('sharedBlock');
        $block = SharedBlock::query()->find(is_string($id) && ctype_digit($id) ? (int) $id : 0);

        abort_unless($block instanceof SharedBlock, 404);

        return $block;
    }

    private function listUrl(): string
    {
        return route('tbtop.cms.shared-blocks-page');
    }
}
