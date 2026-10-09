<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Tab;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EnumLabel;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionRetrier;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Form\Support\LeadIdentity;
use Tbtop\Cms\Language\Languages;

class FormSubmissionsPage extends AdminPage
{
    public static function path(): string
    {
        return 'form-submissions';
    }

    /**
     * Hidden from the sidebar — reached via the top-bar Inbox button.
     *
     * @return array<string, mixed>|null
     */
    public static function nav(): ?array
    {
        return null;
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.submissions');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::form-submissions.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::form-submissions.subtitle');
    }

    /**
     * Reuses the top-bar Inbox label — this page has no nav entry of its
     * own, it's only ever reached via that button.
     *
     * @return list<array{label: string, url?: string}>
     */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::chrome.inbox.label')],
        ];
    }

    public function view(S $s): Node
    {
        $table = $s->table('formSubmissions')
            ->query($this->query(...))
            ->columns([
                // Mirrors the Pages table shape (identity -> attributes ->
                // status -> date) so status sits in the same place across
                // admin tables. Delivery and locale left the list — both live
                // on the detail page; failed rows still surface via the
                // delivery filter and the retry row action.
                Column::make('payload')->label(__('tbtop-cms::form-submissions.columns.lead'))
                    ->formatUsing($this->leadLabel(...))->emphasized(),
                Column::make('kind')->label(__('tbtop-cms::form-submissions.columns.form')),
                Column::make('page')->label(__('tbtop-cms::form-submissions.columns.page'))
                    ->formatUsing($this->formatPage(...)),
                Column::make('lead_status')->label(__('tbtop-cms::form-submissions.columns.status'))
                    ->formatUsing($this->leadStatusLabel(...))
                    ->badge([
                        __('tbtop-cms::form-submissions.statuses.new') => ['color' => Color::Danger],
                        __('tbtop-cms::form-submissions.statuses.read') => ['color' => Color::Info],
                        __('tbtop-cms::form-submissions.statuses.processed') => ['color' => Color::Success],
                        __('tbtop-cms::form-submissions.statuses.discarded') => ['color' => Color::Gray],
                    ]),
                Column::make('created_at')->label(__('tbtop-cms::form-submissions.columns.submitted'))
                    ->datetime(__('tbtop-cms::form-submissions.date_format'))->muted(),
                // Not shown by default — projected raw purely so retry's
                // hiddenIf() can read it: a row carries only declared columns.
                Column::make('status')->label(__('tbtop-cms::form-submissions.columns.delivery'))
                    ->toggleable(true, hiddenByDefault: true),
            ])
            ->tabs($this->tabs())
            ->filters($this->filters($s))
            ->defaultSort('created_at', 'desc')
            ->emptyState(
                __('tbtop-cms::form-submissions.empty.title'),
                __('tbtop-cms::form-submissions.empty.description'),
                'inbox',
            )
            // Row click opens the detail page (FormSubmissionPage), which now
            // owns payload viewing, status changes, retry, and delete for a
            // single record — see that class. markProcessed/discard row
            // actions moved there too; retry stays here so a failed row can
            // be retried without leaving the list.
            ->recordUrl(static fn (FormSubmission $row): string => route('tbtop.cms.form-submission-page', ['submission' => $row->id]))
            ->rowActions([
                $this->retryAction($s),
                $this->deleteAction($s),
            ])
            ->bulkActions([
                $this->markProcessedBulkAction($s),
                $this->discardBulkAction($s),
                $this->deleteBulkAction($s),
            ]);

        return $s->stack([$table]);
    }

    /** @return Builder<FormSubmission> */
    private function query(): Builder
    {
        return FormSubmission::query()->with('page.slugs');
    }

    /**
     * All / New / Read / Processed / Discarded — mirrors PagesPage's tabs
     * pattern (one Tab per meaningful partition, first tab is the default
     * with no scope). "All" carries no lead_status filter of its own, unlike
     * the others, which each narrow to a single LeadStatus case.
     *
     * @return list<Tab>
     */
    private function tabs(): array
    {
        return [
            Tab::make('all')->label(__('tbtop-cms::form-submissions.tabs.all')),
            $this->leadStatusTab('new', LeadStatus::New),
            $this->leadStatusTab('read', LeadStatus::Read),
            $this->leadStatusTab('processed', LeadStatus::Processed),
            $this->leadStatusTab('discarded', LeadStatus::Discarded),
        ];
    }

    private function leadStatusTab(string $name, LeadStatus $status): Tab
    {
        /** @param  Builder<FormSubmission>  $q @return Builder<FormSubmission> */
        $scope = static fn (Builder $q): Builder => $q->where('lead_status', $status->value);

        return Tab::make($name)->label(__('tbtop-cms::form-submissions.statuses.' . $status->value))->query($scope);
    }

    /**
     * Delivery status and locale — both plain `select` fields, which
     * TableFilterApplier already maps to WHERE = with zero custom wiring
     * (no filterUsing() closure needed, same as PagesPage/RedirectsPage never
     * needing one for their own equality filters).
     *
     * @return list<Field>
     */
    private function filters(S $s): array
    {
        return [
            $s->select('status')->label(__('tbtop-cms::form-submissions.columns.delivery'))
                ->options($this->deliveryOptions()),
            $s->select('locale')->label(__('tbtop-cms::form-submissions.columns.locale'))
                ->options($this->localeOptions()),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function deliveryOptions(): array
    {
        return array_map(
            static fn (SubmissionStatus $status): array => [
                'value' => $status->value,
                'label' => __('tbtop-cms::form-submissions.delivery.' . $status->value),
            ],
            SubmissionStatus::cases(),
        );
    }

    /**
     * Admin-active locales, not frontend-active: a submission's locale
     * reflects whatever locale the page was viewed in when the form was
     * submitted, which can include a locale an editor later deactivated on
     * the frontend but is still managing content for — same reasoning
     * Languages::adminActive() documents for its other admin-surface callers.
     *
     * @return list<array{value: string, label: string}>
     */
    private function localeOptions(): array
    {
        return array_map(
            static fn (array $row): array => ['value' => $row['locale'], 'label' => $row['name']],
            app(Languages::class)->adminActive(),
        );
    }

    private function leadStatusLabel(mixed $value): string
    {
        return EnumLabel::make('tbtop-cms::form-submissions.statuses', $value);
    }

    /**
     * The 'payload' column formats its own raw value, exactly like every
     * other formatUsing() column here (e.g. 'page' formats the projected
     * page array) — LeadIdentity::label() never touches the DB, so this
     * costs nothing extra over what the row query already fetched.
     */
    private function leadLabel(mixed $value): string
    {
        $payload = is_array($value) ? $value : [];

        return LeadIdentity::label($payload) ?? __('tbtop-cms::form-submissions.values.lead_unknown');
    }

    /** @param  array<string, mixed>|null  $page */
    private function formatPage(?array $page): string
    {
        if ($page === null) {
            return __('tbtop-cms::form-submissions.values.page_deleted');
        }

        $name = $this->firstLocaleValue($page['name'] ?? null)
            ?? $this->firstLocaleValue($page['title'] ?? null);
        if ($name !== null) {
            return $name;
        }

        $path = $page['slugs'][0]['path'] ?? null;

        return $path !== null
            ? (string) $path
            : __('tbtop-cms::form-submissions.values.page_number', ['id' => $page['id']]);
    }

    private function firstLocaleValue(mixed $raw): ?string
    {
        if (is_string($raw)) {
            return trim($raw) !== '' ? $raw : null;
        }

        if (! is_array($raw)) {
            return null;
        }

        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function retryAction(S $s): ActionBuilder
    {
        // With no delivery column formatting the row, status reaches the
        // client as the raw enum value — hiddenIf compares against it
        // directly (no more coupling to the translated label).
        return $s->action('retry')->label(__('tbtop-cms::form-submissions.actions.retry'))
            ->handle($this->retry(...), needs: ['row'])
            ->hiddenIf('status', '!=', SubmissionStatus::Failed->value);
    }

    private function retry(ActionCtx $ctx): Effects
    {
        $submission = FormSubmission::query()->findOrFail((int) ($ctx->row['id'] ?? 0));

        app(SubmissionRetrier::class)->retry($submission);
        $submission->refresh();
        $this->logSubmission(
            $submission,
            'delivery_retried',
            context: ['result' => $submission->status->value],
        );

        return $submission->status === SubmissionStatus::Handled
            ? Effects::make()->notify(__('tbtop-cms::form-submissions.actions.retried'))->refreshTable()
            : Effects::make()->notify((string) $submission->error, 'error')->refreshTable();
    }

    /**
     * Hard delete, not soft — GDPR/retention: a lead's contact details
     * (name/email/message in the built-in ContactForm, or whatever a custom
     * block's payload carries) must be actually removable on request, not
     * merely hidden behind a trashed scope the way pages/languages are.
     */
    private function deleteAction(S $s): ActionBuilder
    {
        return $s->action('deleteSubmission')->label(__('tbtop-cms::form-submissions.actions.delete'))
            ->color('danger')
            ->confirm(
                __('tbtop-cms::form-submissions.actions.delete_title'),
                __('tbtop-cms::form-submissions.actions.delete_confirm'),
            )
            ->handle($this->delete(...), needs: ['row']);
    }

    private function delete(ActionCtx $ctx): Effects
    {
        $submission = FormSubmission::query()->findOrFail((int) ($ctx->row['id'] ?? 0));
        $submission->delete();
        $this->logSubmission($submission, 'deleted');

        return Effects::make()->notify(__('tbtop-cms::form-submissions.actions.deleted'))->refreshTable();
    }

    private function markProcessedBulkAction(S $s): ActionBuilder
    {
        return $s->action('markProcessedSelected')->label(__('tbtop-cms::form-submissions.actions.mark_processed'))
            ->confirm(
                __('tbtop-cms::form-submissions.actions.mark_processed_bulk_title'),
                __('tbtop-cms::form-submissions.actions.mark_processed_bulk_confirm'),
            )
            ->handle($this->markProcessedBulk(...), needs: ['selection']);
    }

    private function markProcessedBulk(ActionCtx $ctx): Effects
    {
        $this->applyLeadStatus($ctx->selection, LeadStatus::Processed);

        return Effects::make()->notify(__('tbtop-cms::form-submissions.actions.marked_processed'))->refreshTable();
    }

    private function discardBulkAction(S $s): ActionBuilder
    {
        return $s->action('discardSelected')->label(__('tbtop-cms::form-submissions.actions.discard'))
            ->confirm(
                __('tbtop-cms::form-submissions.actions.discard_bulk_title'),
                __('tbtop-cms::form-submissions.actions.discard_bulk_confirm'),
            )
            ->handle($this->discardBulk(...), needs: ['selection']);
    }

    private function discardBulk(ActionCtx $ctx): Effects
    {
        $this->applyLeadStatus($ctx->selection, LeadStatus::Discarded);

        return Effects::make()->notify(__('tbtop-cms::form-submissions.actions.discarded'))->refreshTable();
    }

    private function deleteBulkAction(S $s): ActionBuilder
    {
        return $s->action('deleteSelected')->label(__('tbtop-cms::form-submissions.actions.delete'))
            ->color('danger')
            ->confirm(
                __('tbtop-cms::form-submissions.actions.delete_bulk_title'),
                __('tbtop-cms::form-submissions.actions.delete_bulk_confirm'),
            )
            ->handle($this->deleteBulk(...), needs: ['selection']);
    }

    private function deleteBulk(ActionCtx $ctx): Effects
    {
        $submissions = FormSubmission::query()->whereKey($ctx->selection)->get();
        FormSubmission::query()->whereKey($ctx->selection)->delete();
        app(AuditContext::class)->batch(function () use ($submissions): void {
            foreach ($submissions as $submission) {
                $this->logSubmission($submission, 'deleted');
            }
        });

        return Effects::make()->notify(__('tbtop-cms::form-submissions.actions.deleted'))->refreshTable();
    }

    /** @param  list<int|string>  $ids */
    private function applyLeadStatus(array $ids, LeadStatus $status): void
    {
        if ($ids === []) {
            return;
        }

        $submissions = FormSubmission::query()->whereKey($ids)->get();
        FormSubmission::query()->whereKey($ids)->update(['lead_status' => $status->value]);
        app(AuditContext::class)->batch(function () use ($submissions, $status): void {
            foreach ($submissions as $submission) {
                if ($submission->lead_status === $status) {
                    continue;
                }

                $this->logSubmission($submission, 'status_changed', [
                    'attributes' => ['lead_status' => $status->value],
                    'old' => ['lead_status' => $submission->lead_status->value],
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $context
     */
    private function logSubmission(
        FormSubmission $submission,
        string $event,
        array $changes = [],
        array $context = [],
    ): void {
        app(AuditContext::class)->log(
            $submission,
            $event,
            $submission->kind . ' #' . $submission->getKey(),
            $changes,
            [
                'page_id' => $submission->page_id,
                'kind' => $submission->kind,
                'locale' => $submission->locale,
                ...$context,
            ],
        );
    }
}
