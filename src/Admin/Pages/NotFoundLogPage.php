<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Actions\DeleteAction;
use Tbtop\Admin\Dsl\Actions\RecordAction;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Tab;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\RedirectForm;
use Tbtop\Cms\Admin\Pages\Support\RedirectFormOptions;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\Redirect\Models\Redirect;

/**
 * Redirect-adjacent, deliberately: the operator working the 404 log is the
 * same one who manages redirects, and the fix for a logged miss is almost
 * always "add a redirect" — see NotFoundLogPage::can()'s ability choice.
 */
class NotFoundLogPage extends AdminPage
{
    public static function path(): string
    {
        return 'not-found';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'content',
            'label' => __('tbtop-cms::nav.items.not_found'),
            'order' => 30,
            'icon' => 'file-question',
        ];
    }

    /**
     * Shares tbtop-cms.redirects rather than declaring a new ability: the
     * 404 log is redirect-adjacent work for the same operator, and adding a
     * dedicated ability would mean editing CmsAbilities::ALL for a
     * distinction hosts have no reason to separate (see
     * SectionAbilitiesTest's drift guard).
     */
    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.redirects');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::not-found.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::not-found.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.content')],
            ['label' => __('tbtop-cms::nav.items.not_found')],
        ];
    }

    public function view(S $s): Node
    {
        $table = $s->table('notFoundHits')
            ->query($this->query(...))
            ->columns([
                Column::make('path')->label(__('tbtop-cms::not-found.columns.path'))->searchable()->emphasized(),
                Column::make('locale')->label(__('tbtop-cms::not-found.columns.locale')),
                Column::make('hits')->label(__('tbtop-cms::not-found.columns.hits'))->sortable()->align('right'),
                Column::make('last_seen_at')->label(__('tbtop-cms::not-found.columns.last_seen_at'))
                    ->datetime()->sortable(),
                Column::make('last_referer')->label(__('tbtop-cms::not-found.columns.last_referer'))
                    ->toggleable(true, hiddenByDefault: true)->muted(),
                Column::make('last_user_agent')->label(__('tbtop-cms::not-found.columns.last_user_agent'))
                    ->toggleable(true, hiddenByDefault: true)->muted(),
            ])
            ->tabs([
                Tab::make('active')->label(__('tbtop-cms::not-found.tabs.active'))->count()
                    ->query(static fn (Builder $q): Builder => $q->where('is_ignored', false)),
                Tab::make('ignored')->label(__('tbtop-cms::not-found.tabs.ignored'))->count()
                    ->query(static fn (Builder $q): Builder => $q->where('is_ignored', true)),
            ])
            ->searchable(['path'])
            ->defaultSort('last_seen_at', 'desc')
            ->paginate(25)
            ->emptyState(
                __('tbtop-cms::not-found.empty.title'),
                __('tbtop-cms::not-found.empty.description'),
                'file-question',
            )
            ->rowActions([
                $this->createRedirectAction($s),
                $this->ignoreAction($s),
                $this->unignoreAction($s),
                $this->deleteAction($s),
            ])
            ->bulkActions([$this->bulkIgnoreAction($s), $this->bulkDeleteAction($s)]);

        return $s->stack([$table]);
    }

    /**
     * `has_redirect` mirrors RedirectResolver::match(): a redirect for the
     * hit's own locale or a locale-agnostic one. It is a row flag, not a
     * column, so createRedirectAction() can hide itself per row
     * (ColumnProjection ships every model attribute, not only declared
     * columns). A hit that already redirects is dealt with — the modal would
     * only end in RedirectValidator's "from_taken" error.
     *
     * @return Builder<NotFoundHit>
     */
    private function query(): Builder
    {
        $redirectExists = Redirect::query()->selectRaw('1')
            ->whereColumn('tbtop_redirects.from_path', 'tbtop_not_found_hits.path')
            ->where(static fn (Builder $q): Builder => $q
                ->whereColumn('tbtop_redirects.locale', 'tbtop_not_found_hits.locale')
                ->orWhereNull('tbtop_redirects.locale'))
            ->limit(1);

        return NotFoundHit::query()
            ->select('tbtop_not_found_hits.*')
            ->selectSub($redirectExists, 'has_redirect');
    }

    /**
     * from_path/locale are read from the row context server-side rather than
     * round-tripped through the form (a disabled field's value is a client
     * concern, not a guaranteed payload) — the operator only fills in the
     * target and picks a status. For the same reason from_path carries no
     * `required`: the form's rules run against the action payload before the
     * handler, and the real guard is RedirectForm::save(), which validates
     * the row-sourced value. Saving writes through RedirectForm::save(), the
     * same path RedirectsPage's own create modal uses, rather than
     * hand-rolling redirect creation here.
     */
    private function createRedirectAction(S $s): ActionBuilder
    {
        $cancel = $s->action('notFoundCreateRedirectCancel')->label(__('tbtop-cms::not-found.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $save = $s->action('notFoundCreateRedirectSave')->label(__('tbtop-cms::not-found.actions.create_redirect'))
            ->color('primary')->handle($this->createRedirect(...), needs: ['row', 'form']);

        $form = $s->form('notFoundCreateRedirect', [
            $s->text('from_path')->label(__('tbtop-cms::redirects.fields.from_path'))
                ->meta('disabled', true),
            $s->select('locale')->label(__('tbtop-cms::redirects.fields.locale'))->options(RedirectFormOptions::locales()),
            $s->text('to_path')->label(__('tbtop-cms::not-found.fields.to_path'))->required()
                ->helperText(__('tbtop-cms::redirects.helpers.to_path')),
            $s->select('status_code')->label(__('tbtop-cms::not-found.fields.status_code'))->required()
                ->options(RedirectFormOptions::statuses()),
            $s->flex([$s->spacer(), $cancel, $save], justify: 'end'),
        ]);

        return $s->action('notFoundCreateRedirect')->label(__('tbtop-cms::not-found.actions.create_redirect'))
            ->query(static fn (ActionCtx $ctx): array => [
                'from_path' => (string) ($ctx->row['path'] ?? ''),
                'locale' => self::localeOptionFor((string) ($ctx->row['locale'] ?? '')),
                'to_path' => '',
                'status_code' => '301',
            ], needs: ['row'])
            ->modal(__('tbtop-cms::not-found.actions.create_redirect_title'), $form)
            ->hiddenIf(Cond::truthy('has_redirect'));
    }

    private static function localeOptionFor(string $locale): string
    {
        return $locale === '' ? RedirectFormOptions::ALL_LOCALES : $locale;
    }

    private function createRedirect(ActionCtx $ctx): Effects
    {
        $rowPath = (string) ($ctx->row['path'] ?? '');
        $rowLocale = self::localeOptionFor((string) ($ctx->row['locale'] ?? ''));

        try {
            RedirectForm::save([...$ctx->form, 'from_path' => $rowPath, 'locale' => $ctx->form['locale'] ?? $rowLocale]);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return Effects::make()->haltModal(is_string($first) ? $first : $e->getMessage());
        }

        $hit = NotFoundHit::query()->find($ctx->row['id'] ?? null);
        $hit?->forceFill(['is_ignored' => true])->save();
        if ($hit !== null) {
            self::logHit($hit, 'ignored');
        }

        return Effects::make()->notify(__('tbtop-cms::not-found.actions.create_redirect_saved'))
            ->closeModal()->refreshTable();
    }

    /**
     * Two actions rather than one dynamically-labeled toggle — same shape as
     * PagesPage's restore/forceDelete pair: each hiddenIf()s on the opposite
     * of the other's condition, so exactly one is visible per row.
     */
    private function ignoreAction(S $s): ActionBuilder
    {
        return RecordAction::server($s, 'notFoundIgnore', static function (ActionCtx $ctx): Effects {
            $hit = NotFoundHit::query()->findOrFail($ctx->row['id'] ?? null);
            $hit->forceFill(['is_ignored' => true])->save();
            self::logHit($hit, 'ignored');

            return Effects::make()->notify(__('tbtop-cms::not-found.actions.ignored'))->refreshTable();
        })
            ->label(__('tbtop-cms::not-found.actions.ignore'))
            ->hiddenIf(Cond::eq('is_ignored', true));
    }

    private function unignoreAction(S $s): ActionBuilder
    {
        return RecordAction::server($s, 'notFoundUnignore', static function (ActionCtx $ctx): Effects {
            $hit = NotFoundHit::query()->findOrFail($ctx->row['id'] ?? null);
            $hit->forceFill(['is_ignored' => false])->save();
            self::logHit($hit, 'unignored');

            return Effects::make()->notify(__('tbtop-cms::not-found.actions.unignored'))->refreshTable();
        })
            ->label(__('tbtop-cms::not-found.actions.unignore'))
            ->hiddenIf(Cond::eq('is_ignored', false));
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static function (ActionCtx $ctx): Effects {
                $hit = NotFoundHit::query()->findOrFail($ctx->row['id'] ?? null);
                $hit->delete();
                self::logHit($hit, 'deleted');

                return Effects::make()->notify(__('tbtop-cms::not-found.actions.deleted'))->refreshTable();
            },
            'notFoundDelete',
        )->label(__('tbtop-cms::not-found.actions.delete'))
            ->confirm(__('tbtop-cms::not-found.actions.delete_title'), __('tbtop-cms::not-found.actions.delete_confirm'));
    }

    private function bulkIgnoreAction(S $s): ActionBuilder
    {
        return RecordAction::bulk($s, 'notFoundBulkIgnore', static function (ActionCtx $ctx): Effects {
            $hits = NotFoundHit::query()->whereIn('id', $ctx->selection)->get();
            NotFoundHit::query()->whereIn('id', $ctx->selection)->update(['is_ignored' => true]);
            app(AuditContext::class)->batch(static function () use ($hits): void {
                foreach ($hits as $hit) {
                    if (! $hit->is_ignored) {
                        self::logHit($hit, 'ignored');
                    }
                }
            });

            return Effects::make()->notify(__('tbtop-cms::not-found.actions.bulk_ignore_done'))->refreshTable();
        })->label(__('tbtop-cms::not-found.actions.bulk_ignore'));
    }

    private function bulkDeleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static function (ActionCtx $ctx): Effects {
                $hits = NotFoundHit::query()->whereIn('id', $ctx->selection)->get();
                NotFoundHit::query()->whereIn('id', $ctx->selection)->delete();
                app(AuditContext::class)->batch(static function () use ($hits): void {
                    foreach ($hits as $hit) {
                        self::logHit($hit, 'deleted');
                    }
                });

                return Effects::make()->notify(__('tbtop-cms::not-found.actions.deleted'))->refreshTable();
            },
            'notFoundBulkDelete',
            bulk: true,
        );
    }

    private static function logHit(NotFoundHit $hit, string $event): void
    {
        app(AuditContext::class)->log(
            $hit,
            $event,
            $hit->path,
            context: ['path' => $hit->path, 'locale' => $hit->locale],
        );
    }
}
