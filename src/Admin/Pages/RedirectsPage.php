<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Actions\DeleteAction;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\RedirectForm;
use Tbtop\Cms\Admin\Support\AuthorNames;
use Tbtop\Cms\Redirect\Models\Redirect;

class RedirectsPage extends AdminPage
{
    public static function path(): string
    {
        return 'redirects';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'content',
            'label' => __('tbtop-cms::nav.items.redirects'),
            'order' => 20,
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return 'tbtop-cms.redirects';
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::redirects.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::redirects.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.content')],
            ['label' => __('tbtop-cms::nav.items.redirects')],
        ];
    }

    /** @return list<ActionBuilder|Node> */
    #[\Override]
    public function headerActions(S $s): array
    {
        return [$this->createAction($s)];
    }

    public function view(S $s): Node
    {
        $table = $s->table('redirects')
            ->query($this->query(...))
            ->columns([
                Column::make('from_path')->label(__('tbtop-cms::redirects.columns.from'))->searchable(),
                Column::make('to_path')->label(__('tbtop-cms::redirects.columns.to'))->searchable(),
                Column::make('locale')->label(__('tbtop-cms::redirects.columns.locale'))
                    ->formatUsing($this->localeLabel(...)),
                Column::make('status_code')->label(__('tbtop-cms::redirects.columns.status'))
                    ->formatUsing($this->statusLabel(...)),
                Column::make('is_auto')->label(__('tbtop-cms::redirects.columns.auto'))->boolean(),
                Column::make('updated_by_name')->label(__('tbtop-cms::redirects.columns.updated_by'))
                    ->toggleable(true, hiddenByDefault: true)->muted(),
            ])
            ->searchable(['from_path', 'to_path'])
            ->defaultSort('from_path', 'asc')
            ->emptyState(
                __('tbtop-cms::redirects.empty.title'),
                __('tbtop-cms::redirects.empty.description'),
                'arrow-right',
            )
            ->rowActions([$this->editAction($s), $this->deleteAction($s)]);

        return $s->stack([$table]);
    }

    /** @return Builder<Redirect> */
    private function query(): Builder
    {
        return Redirect::query()->select('redirects.*')
            ->selectSub(AuthorNames::subselect('redirects', 'updated_by'), 'updated_by_name');
    }

    private function createAction(S $s): ActionBuilder
    {
        $cancel = $s->action('redirectCreateCancel')->label(__('tbtop-cms::redirects.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $create = $s->action('redirectCreateSave')->label(__('tbtop-cms::redirects.actions.create'))->color('primary')
            ->handle($this->create(...), needs: ['form']);

        $form = $s->form('redirectCreate', [
            ...RedirectForm::fields($s),
            $s->flex([$s->spacer(), $cancel, $create], justify: 'end'),
        ]);

        return $s->action('redirectCreate')->label(__('tbtop-cms::redirects.actions.new'))
            ->icon('plus')->color('primary')
            ->modal(__('tbtop-cms::redirects.actions.create_title'), $form);
    }

    /**
     * Hand-built instead of EditAction::make: the prebuilt helper hardcodes
     * English Save/Cancel and a left-aligned actionsRow — this mirrors the
     * create modal (localized labels, footer flex justify:end) instead.
     */
    private function editAction(S $s): ActionBuilder
    {
        $cancel = $s->action('redirectEditCancel')->label(__('tbtop-cms::redirects.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $save = $s->action('redirectEditSave')->label(__('tbtop-cms::redirects.actions.save'))->color('primary')
            ->handle($this->update(...), needs: ['row', 'form']);

        $form = $s->form('redirectEdit', [
            ...RedirectForm::fields($s),
            $s->flex([$s->spacer(), $cancel, $save], justify: 'end'),
        ]);

        return $s->action('redirectEdit')->label(__('tbtop-cms::redirects.actions.edit'))
            ->modal(__('tbtop-cms::redirects.actions.edit_title'), $form)
            ->query(static fn (ActionCtx $ctx): array => RedirectForm::loadRow((int) ($ctx->row['id'] ?? 0)), needs: ['row']);
    }

    private function update(ActionCtx $ctx): Effects
    {
        try {
            RedirectForm::save($ctx->form, (int) ($ctx->row['id'] ?? 0));
        } catch (ValidationException $e) {
            return self::haltWithErrors($e);
        }

        return Effects::make()->notify(__('tbtop-cms::redirects.actions.saved'))->closeModal()->refreshTable();
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static function (ActionCtx $ctx): Effects {
                Redirect::query()->find($ctx->row['id'] ?? null)?->delete();

                return Effects::make()->notify(__('tbtop-cms::redirects.actions.deleted'))->refreshTable();
            },
            'redirectDelete',
        )->label(__('tbtop-cms::redirects.actions.delete'))
            ->confirm(__('tbtop-cms::redirects.actions.delete_title'), __('tbtop-cms::redirects.actions.delete_confirm'));
    }

    private function localeLabel(mixed $value): string
    {
        return $value === null || $value === '' ? __('tbtop-cms::redirects.locale_all') : (string) $value;
    }

    private function statusLabel(mixed $value): string
    {
        $key = 'tbtop-cms::redirects.statuses.' . $value;
        $label = __($key);

        return $label === $key ? (string) $value : $label;
    }

    private function create(ActionCtx $ctx): Effects
    {
        try {
            RedirectForm::save($ctx->form);
        } catch (ValidationException $e) {
            return self::haltWithErrors($e);
        }

        return Effects::make()->notify(__('tbtop-cms::redirects.actions.created'))->closeModal()->refreshTable();
    }

    /**
     * Action endpoints have no field-error channel (a thrown
     * ValidationException turns into a redirect the client silently
     * ignores) — surface the message inside the still-open modal instead.
     */
    private static function haltWithErrors(ValidationException $e): Effects
    {
        $first = collect($e->errors())->flatten()->first();

        return Effects::make()->haltModal(is_string($first) ? $first : $e->getMessage());
    }
}
