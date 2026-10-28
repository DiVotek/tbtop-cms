<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Collections\CollectionTable;
use Tbtop\Cms\Admin\Pages\Support\EntryForm;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Collection\Collection;
use Tbtop\Cms\Collection\CollectionRegistry;

/**
 * One page for every collection, `collections/{collection}`: the entries
 * table and an "Add" modal with the collection's form. Its menu items come
 * from CmsPanel (one per collection), since a page with a route parameter
 * has no menu item of its own. Collections are content, so the page answers
 * to the pages ability.
 */
class CollectionEntriesPage extends AdminPage
{
    public static function path(): string
    {
        return 'collections/{collection}';
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.pages');
    }

    #[\Override]
    public function title(): string
    {
        return $this->collection()->label();
    }

    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.' . $this->collection()->navigationGroup())],
            ['label' => $this->collection()->label()],
        ];
    }

    #[\Override]
    public function headerActions(S $s): array
    {
        return [$this->createAction($s)];
    }

    public function view(S $s): Node
    {
        return $s->stack([CollectionTable::make($s, $this->collection()->key())->build()]);
    }

    private function createAction(S $s): ActionBuilder
    {
        $collection = $this->collection();
        $cancel = $s->action('entryCreateCancel')->label(__('tbtop-cms::common.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $create = $s->action('entryCreateSave')->label(__('tbtop-cms::collections.actions.create'))->color('primary')
            ->handle(fn (ActionCtx $ctx): Effects => $this->create($ctx), needs: ['form']);

        $form = $s->form('entryCreate', [
            ...EntryForm::fields($s, $collection),
            $s->flex([$s->spacer(), $cancel, $create], justify: 'end'),
        ])->record(['status' => 'published']);

        return $s->action('entryCreate')->label(__('tbtop-cms::collections.actions.new', ['name' => $collection->singularLabel()]))
            ->icon('plus')->color('primary')
            ->modal(__('tbtop-cms::collections.actions.new', ['name' => $collection->singularLabel()]), $form);
    }

    private function create(ActionCtx $ctx): Effects
    {
        $collection = $this->collection();
        $entry = EntryForm::create($collection, $ctx->form);

        return Effects::make()->closeModal()
            ->notify(__('tbtop-cms::collections.actions.saved'))
            ->redirect(CollectionTable::editUrl($collection->key(), (int) $entry->getKey()));
    }

    private function collection(): Collection
    {
        $key = request()->route('collection');
        $registry = app(CollectionRegistry::class);

        abort_unless(is_string($key) && $registry->has($key), 404);

        return $registry->get($key);
    }
}
