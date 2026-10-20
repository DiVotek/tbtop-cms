<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Actions\DeleteAction;
use Tbtop\Admin\Dsl\Actions\EditAction;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Tab;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\LanguageForm;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Language\Models\Language;

class LanguagesPage extends AdminPage
{
    public static function path(): string
    {
        return 'languages';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'settings',
            'label' => __('tbtop-cms::nav.items.languages'),
            'order' => 10,
            'icon' => 'languages',
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.languages');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::languages.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::languages.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => __('tbtop-cms::nav.items.languages')],
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
        $table = $s->table('languages')
            ->query(static fn (): Builder => Language::query())
            ->columns([
                Column::make('name')->label(__('tbtop-cms::languages.columns.name'))->searchable(),
                Column::make('locale')->label(__('tbtop-cms::languages.columns.locale'))->searchable(),
                Column::make('is_default')->label(__('tbtop-cms::languages.columns.default'))->boolean(),
                Column::make('is_admin_active')->label(__('tbtop-cms::languages.columns.admin'))->boolean(),
                Column::make('is_frontend_active')->label(__('tbtop-cms::languages.columns.frontend'))->boolean(),
                Column::make('sort_order')->label(__('tbtop-cms::languages.columns.order')),
                // Not shown by default — projected purely so hiddenIf() can
                // distinguish trashed rows client-side (empty vs non-empty).
                Column::make('deleted_at')->label(__('tbtop-cms::languages.columns.deleted_at'))
                    ->date()->toggleable(true, hiddenByDefault: true),
            ])
            ->tabs([
                Tab::make('all')->label(__('tbtop-cms::languages.tabs.all'))
                    ->query($this->noopScope(...)),
                Tab::make('deleted')->label(__('tbtop-cms::languages.tabs.deleted'))
                    ->description(__('tbtop-cms::languages.tabs_deleted_description'))
                    ->query($this->onlyTrashedScope(...)),
            ])
            ->searchable(['name', 'locale'])
            ->defaultSort('sort_order', 'asc')
            ->emptyState(
                __('tbtop-cms::languages.empty.title'),
                __('tbtop-cms::languages.empty.description'),
                'languages',
            )
            ->rowActions([
                $this->editAction($s),
                $this->deleteAction($s),
                $this->restoreAction($s),
                $this->forceDeleteAction($s),
            ]);

        return $s->stack([$table]);
    }

    /**
     * @param  Builder<Language>  $q
     * @return Builder<Language>
     */
    private function noopScope(Builder $q): Builder
    {
        return $q;
    }

    /**
     * @param  Builder<Language>  $q
     * @return Builder<Language>
     */
    private function onlyTrashedScope(Builder $q): Builder
    {
        return $q->onlyTrashed();
    }

    private function createAction(S $s): ActionBuilder
    {
        $cancel = $s->action('languageCreateCancel')->label(__('tbtop-cms::common.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $create = $s->action('languageCreateSave')->label(__('tbtop-cms::languages.actions.create'))->color('primary')
            ->handle($this->create(...), needs: ['form']);

        $form = $s->form('languageCreate', [
            ...LanguageForm::createFields($s),
            $s->flex([$s->spacer(), $cancel, $create], justify: 'end'),
        ]);

        return $s->action('languageCreate')->label(__('tbtop-cms::languages.actions.new'))
            ->icon('plus')->color('primary')
            ->modal(__('tbtop-cms::languages.create_title'), $form);
    }

    private function editAction(S $s): ActionBuilder
    {
        return EditAction::make(
            $s,
            $s->form('languageEdit', LanguageForm::editFields($s)),
            static fn (ActionCtx $ctx): array => LanguageForm::load((int) $ctx->key()),
            static fn (ActionCtx $ctx): ?Effects => self::saveOrHalt($ctx->form, (int) $ctx->key()),
            name: 'languageEdit',
            title: __('tbtop-cms::languages.edit_title'),
        )->hiddenIf('deleted_at', 'not empty');
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static function (ActionCtx $ctx): Effects {
                try {
                    Language::query()->find($ctx->key())?->delete();
                } catch (DomainException $e) {
                    return Effects::make()->notify($e->getMessage(), 'error');
                }

                return Effects::make()->notify(__('tbtop-cms::languages.actions.deleted'))->refreshTable();
            },
            'languageDelete',
        )->label(__('tbtop-cms::languages.actions.delete'))
            ->confirm(__('tbtop-cms::languages.actions.delete_title'), __('tbtop-cms::languages.actions.delete_confirm'))
            ->hiddenIf(Cond::any(Cond::eq('is_default', true), Cond::notEmpty('deleted_at')));
    }

    private function restoreAction(S $s): ActionBuilder
    {
        return $s->action('languageRestore')->label(__('tbtop-cms::languages.actions.restore'))->color('gray')
            ->handle($this->restore(...), needs: ['row'])
            ->hiddenIf('deleted_at', 'empty');
    }

    private function restore(ActionCtx $ctx): Effects
    {
        $language = Language::withTrashed()->findOrFail((int) $ctx->key());

        try {
            $language->restore();
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::languages.actions.restored'))->refreshTable();
    }

    private function forceDeleteAction(S $s): ActionBuilder
    {
        return $s->action('languageForceDelete')->label(__('tbtop-cms::languages.actions.force_delete'))->color('danger')
            ->confirm(__('tbtop-cms::languages.actions.force_delete_title'), __('tbtop-cms::languages.actions.force_delete_confirm'))
            ->handle($this->forceDelete(...), needs: ['row'])
            ->hiddenIf('deleted_at', 'empty');
    }

    private function forceDelete(ActionCtx $ctx): Effects
    {
        $language = Language::withTrashed()->findOrFail((int) $ctx->key());

        try {
            app(AuditContext::class)->during(
                'force_deleted',
                [],
                static fn (): ?bool => $language->forceDelete(),
            );
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::languages.actions.force_deleted'))->refreshTable();
    }

    private function create(ActionCtx $ctx): Effects
    {
        return self::saveOrHalt($ctx->form)
            ?? Effects::make()->notify(__('tbtop-cms::languages.actions.created'))->closeModal()->refreshTable();
    }

    /**
     * Action endpoints have no field-error channel (a thrown
     * ValidationException turns into a 422 the client ignores, a
     * DomainException from LanguageObserver into a 500): surface the
     * message inside the still-open modal instead. Null means saved.
     *
     * @param  array<string, mixed>  $form
     */
    private static function saveOrHalt(array $form, ?int $id = null): ?Effects
    {
        try {
            LanguageForm::save($form, $id);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return Effects::make()->haltModal(is_string($first) ? $first : $e->getMessage());
        } catch (DomainException $e) {
            return Effects::make()->haltModal($e->getMessage());
        }

        return null;
    }
}
