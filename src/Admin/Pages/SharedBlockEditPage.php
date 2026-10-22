<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\BlockPicker;
use Tbtop\Cms\Admin\Pages\Support\LayoutForm;
use Tbtop\Cms\Admin\Pages\Support\SharedBlockForm;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EditFooter;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Page\Models\Page as CmsPage;
use Tbtop\Cms\SharedBlock\Exceptions\SharedBlockConflictException;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\SharedBlock\SharedBlockDetacher;
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
            // A hidden field, not just a record key: the form submit only
            // keeps keys a field declares (see PageEditPage::view()).
            $s->number('lock_version')->meta('hidden', true),
            $s->text('name')->label(__('tbtop-cms::shared-blocks.fields.name'))->required(),
            ...SharedBlockForm::dataFields($s, $block),
            $this->usage($s, $block),
            $footer,
        ])
            ->record(SharedBlockForm::recordValues($block))
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    /**
     * Where the block is placed, as a table over the page and layout rows
     * that reference it. A detach here copies the content into that
     * container, so a page can take its own copy without opening its editor.
     * The rows come from a literal union — usage lives in JSON content, not
     * in a table the DSL could query — and the row id names the container.
     */
    private function usage(S $s, SharedBlock $block): Node
    {
        $containers = SharedBlockUsage::using((int) $block->getKey());
        $count = $s->displayText(trans_choice('tbtop-cms::shared-blocks.usage_count', count($containers)))->variant('muted');

        if ($containers === []) {
            return $s->section(['title' => __('tbtop-cms::shared-blocks.usage_title'), 'variant' => 'plain'], [$count]);
        }

        $table = $s->table('sharedBlockUsage')
            ->query(fn (): QueryBuilder => $this->usageQuery($containers))
            ->columns([
                Column::make('name')->label(__('tbtop-cms::shared-blocks.columns.name')),
                Column::make('container')->label(__('tbtop-cms::shared-blocks.columns.container'))->badge([
                    'page' => ['label' => __('tbtop-cms::shared-blocks.containers.page'), 'color' => Color::Info],
                    'trashed_page' => ['label' => __('tbtop-cms::pages.trashed'), 'color' => Color::Warning],
                    'layout' => ['label' => __('tbtop-cms::shared-blocks.containers.layout'), 'color' => Color::Gray],
                ]),
            ])
            ->toolbar(false)
            ->embedded()
            ->recordUrl(fn (object $row): ?string => $this->containerUrl((string) $row->id))
            ->rowActions([
                $s->action('sharedBlockUsageOpen')->label('')->link()->icon('pencil')
                    ->tooltip(__('tbtop-cms::shared-blocks.usage.open'))
                    ->handle(fn (ActionCtx $ctx): Effects => $this->open($ctx), needs: ['row']),
                $this->detachAction($s, $block),
            ]);

        return $s->section(['title' => __('tbtop-cms::shared-blocks.usage_title'), 'variant' => 'plain'], [$s->stack([$count, $table], ['gap' => 2])]);
    }

    /** @param  list<CmsPage|Layout>  $containers */
    private function usageQuery(array $containers): QueryBuilder
    {
        $union = null;
        foreach ($containers as $container) {
            $row = $container instanceof CmsPage
                ? ['page:' . $container->getKey(), $this->pageName($container), $container->trashed() ? 'trashed_page' : 'page']
                : ['layout:' . $container->slot, LayoutForm::label($container->slot), 'layout'];
            $select = DB::query()->selectRaw('? as id, ? as name, ? as container', $row);
            $union = $union instanceof QueryBuilder ? $union->unionAll($select) : $select;
        }

        return DB::query()->fromSub($union, 'usage');
    }

    /**
     * A modal rather than confirm(): the client labels a confirm button with
     * the action's own label, and an icon-only action has none to give.
     */
    private function detachAction(S $s, SharedBlock $block): ActionBuilder
    {
        $cancel = $s->action('sharedBlockUsageDetachCancel')->label(__('tbtop-cms::common.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $detach = $s->action('sharedBlockUsageDetachConfirm')->label(__('tbtop-cms::shared-blocks.reference.detach'))
            ->color('danger')
            ->handle(fn (ActionCtx $ctx): Effects => $this->detach($ctx, $block), needs: ['row']);

        return $s->action('sharedBlockUsageDetach')->label('')->link()->icon('unlink')
            ->tooltip(__('tbtop-cms::shared-blocks.reference.detach'))
            ->modal(__('tbtop-cms::shared-blocks.reference.detach_title'), $s->stack([
                $s->displayText(__('tbtop-cms::shared-blocks.reference.detach_confirm', ['name' => $block->name]))->variant('muted'),
                $s->flex([$s->spacer(), $cancel, $detach], justify: 'end'),
            ], ['gap' => 4]));
    }

    private function open(ActionCtx $ctx): Effects
    {
        $url = $this->containerUrl((string) $ctx->key());

        return $url === null ? Effects::make() : Effects::make()->redirect($url);
    }

    private function detach(ActionCtx $ctx, SharedBlock $block): Effects
    {
        $container = $this->container((string) $ctx->key());
        // Gated like the editor it stands in for: a page is content, a
        // layout is design (the ability this page already holds).
        if ($container !== null && ! $this->allows($container instanceof CmsPage ? 'tbtop-cms.pages' : 'tbtop-cms.design')) {
            abort(403);
        }
        if ($container === null || SharedBlockDetacher::detach($block, $container) === 0) {
            return Effects::make()->notify(__('tbtop-cms::shared-blocks.usage.gone'), 'error')->refreshTable();
        }

        return Effects::make()->notify(__('tbtop-cms::shared-blocks.reference.detached'))
            ->redirect(route('tbtop.cms.shared-block-edit-page', ['sharedBlock' => $block->getKey()]));
    }

    private function allows(string $ability): bool
    {
        $gate = CmsAbilities::for($ability);

        return $gate === null || Gate::allows($gate);
    }

    /** The table row id is `page:<id>` or `layout:<slot>`. */
    private function container(string $rowId): CmsPage | Layout | null
    {
        [$type, $key] = array_pad(explode(':', $rowId, 2), 2, '');

        return match ($type) {
            'page' => ctype_digit($key) ? CmsPage::withTrashed()->find((int) $key) : null,
            'layout' => Layout::query()->where('slot', $key)->first(),
            default => null,
        };
    }

    /** A trashed page has no editor (it 404s), so its row gets no link, as on PagesPage. */
    private function containerUrl(string $rowId): ?string
    {
        [$type, $key] = array_pad(explode(':', $rowId, 2), 2, '');

        return match (true) {
            $type === 'page' && Route::has('tbtop.cms.page-edit-page') => ctype_digit($key) && CmsPage::query()->whereKey((int) $key)->exists()
                ? route('tbtop.cms.page-edit-page', ['page' => $key])
                : null,
            $type === 'layout' && Route::has('tbtop.cms.layout-edit-page') => route('tbtop.cms.layout-edit-page', ['slot' => $key]),
            default => null,
        };
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

    /** A stale form gets a notify only, as on PageEditPage::save(): back() resyncs it to the winner. */
    private function save(ActionCtx $ctx): Effects
    {
        try {
            SharedBlockForm::update($ctx->form, $this->block());
        } catch (SharedBlockConflictException) {
            return Effects::make()->notify(__('tbtop-cms::shared-blocks.conflict.message'), 'error');
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
