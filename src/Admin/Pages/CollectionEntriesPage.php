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
 * Every collection on one page, `collections`: a tab per collection, the
 * built-ins first, each with its entries table and an "Add" modal with the
 * collection's form. The open tab is in the URL (`?tab[collections]=key`,
 * CollectionTable::listUrl()). Collections are content, so the page answers
 * to the pages ability.
 */
class CollectionEntriesPage extends AdminPage
{
    public const string TABS = 'collections';

    public static function path(): string
    {
        return 'collections';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'content',
            'label' => __('tbtop-cms::nav.items.collections'),
            'order' => 15,
            'icon' => 'library',
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.pages');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::collections.title');
    }

    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.content')],
            ['label' => $this->title()],
        ];
    }

    public function view(S $s): Node
    {
        $open = request()->query('tab');
        $open = is_array($open) ? ($open[self::TABS] ?? null) : null;

        $tabs = [];
        foreach (app(CollectionRegistry::class)->all() as $collection) {
            $tabs[] = [
                'name' => $collection->key(),
                'label' => $collection->label(),
                'active' => $collection->key() === $open,
                'children' => [
                    $s->flex([$s->spacer(), $this->createAction($s, $collection)], justify: 'end'),
                    CollectionTable::make($s, $collection->key())->build(),
                ],
            ];
        }

        return $s->tabs($tabs, ['name' => self::TABS]);
    }

    private function createAction(S $s, Collection $collection): ActionBuilder
    {
        $suffix = '_' . str_replace('-', '_', $collection->key());
        $title = __('tbtop-cms::collections.actions.new', ['name' => $collection->singularLabel()]);
        $cancel = $s->action('entryCreateCancel' . $suffix)->label(__('tbtop-cms::common.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $create = $s->action('entryCreateSave' . $suffix)->label(__('tbtop-cms::collections.actions.create'))->color('primary')
            ->handle(static fn (ActionCtx $ctx): Effects => self::create($collection, $ctx), needs: ['form']);

        $form = $s->form('entryCreate' . $suffix, [
            ...EntryForm::fields($s, $collection),
            $s->flex([$s->spacer(), $cancel, $create], justify: 'end'),
        ])->record(['status' => 'published']);

        return $s->action('entryCreate' . $suffix)->label($title)->icon('plus')->color('primary')->modal($title, $form);
    }

    private static function create(Collection $collection, ActionCtx $ctx): Effects
    {
        $entry = EntryForm::create($collection, $ctx->form);

        return Effects::make()->closeModal()
            ->notify(__('tbtop-cms::collections.actions.saved'))
            ->redirect(CollectionTable::editUrl($collection->key(), (int) $entry->getKey()));
    }
}
