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
use Tbtop\Cms\Admin\Support\EditFooter;
use Tbtop\Cms\Collection\Collection;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Collection\Exceptions\EntryConflictException;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Language\Languages;

class CollectionEntryEditPage extends AdminPage
{
    public static function path(): string
    {
        return 'collections/{collection}/{entry}/edit';
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.pages');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::collections.edit_title', ['collection' => $this->collection()->label(), 'name' => $this->name()]);
    }

    #[\Override]
    public function breadcrumbs(): array
    {
        $collection = $this->collection();

        return [
            ['label' => __('tbtop-cms::collections.title'), 'url' => CollectionTable::listUrl($collection->key())],
            ['label' => $collection->label(), 'url' => CollectionTable::listUrl($collection->key())],
            ['label' => $this->name()],
        ];
    }

    public function view(S $s): Node
    {
        $collection = $this->collection();
        $entry = $this->entry();

        $form = $s->form('entryEditor', [
            // A hidden field, not just a record key: the form submit only keeps keys a field declares.
            $s->number('lock_version')->meta('hidden', true),
            ...EntryForm::fields($s, $collection),
            EditFooter::build($s, CollectionTable::listUrl($collection->key()), $this->saveAction($s)),
        ])
            ->record(EntryForm::recordValues($entry))
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function saveAction(S $s): ActionBuilder
    {
        return $s->action('save')->label(__('tbtop-cms::collections.actions.save'))
            ->color('primary')->keybinding('mod+s')->submit('entryEditor');
    }

    /** A stale form gets a notify only, as on SharedBlockEditPage: back() resyncs it to the winner. */
    private function save(ActionCtx $ctx): Effects
    {
        try {
            EntryForm::update($this->collection(), $this->entry(), $ctx->form);
        } catch (EntryConflictException) {
            return Effects::make()->notify(__('tbtop-cms::collections.conflict'), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::collections.actions.saved'));
    }

    private function name(): string
    {
        $default = app(Languages::class)->defaultLocale();

        return $this->entry()->titleIn((string) app()->getLocale(), $default);
    }

    private function collection(): Collection
    {
        $key = request()->route('collection');
        $registry = app(CollectionRegistry::class);

        abort_unless(is_string($key) && $registry->has($key), 404);

        return $registry->get($key);
    }

    private function entry(): Entry
    {
        $id = request()->route('entry');
        $entry = Entry::query()->of($this->collection()->key())->find(is_string($id) && ctype_digit($id) ? (int) $id : 0);

        abort_unless($entry instanceof Entry, 404);

        return $entry;
    }
}
