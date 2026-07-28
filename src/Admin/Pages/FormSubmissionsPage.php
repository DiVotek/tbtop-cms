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
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Support\CmsAbilities;
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
                Column::make('lead_status')->label(__('tbtop-cms::form-submissions.columns.status'))
                    ->formatUsing($this->leadStatusLabel(...))
                    ->badge([
                        __('tbtop-cms::form-submissions.statuses.new') => Color::Danger,
                        __('tbtop-cms::form-submissions.statuses.read') => Color::Info,
                        __('tbtop-cms::form-submissions.statuses.processed') => Color::Success,
                        __('tbtop-cms::form-submissions.statuses.discarded') => Color::Gray,
                    ]),
                // First data column after lead_status: "who is this" outranks
                // "what form/page" for a triage-first inbox — a manager
                // scanning the list wants to know who reached out before
                // which form they used.
                Column::make('payload')->label(__('tbtop-cms::form-submissions.columns.lead'))
                    ->formatUsing($this->leadLabel(...)),
                Column::make('status')->label(__('tbtop-cms::form-submissions.columns.delivery'))
                    ->formatUsing($this->deliveryLabel(...))
                    ->badge([
                        __('tbtop-cms::form-submissions.delivery.pending') => Color::Warning,
                        __('tbtop-cms::form-submissions.delivery.handled') => Color::Success,
                        __('tbtop-cms::form-submissions.delivery.failed') => Color::Danger,
                    ]),
                Column::make('kind')->label(__('tbtop-cms::form-submissions.columns.form')),
                Column::make('page')->label(__('tbtop-cms::form-submissions.columns.page'))
                    ->formatUsing($this->formatPage(...)),
                Column::make('locale')->label(__('tbtop-cms::form-submissions.columns.locale')),
                Column::make('created_at')->label(__('tbtop-cms::form-submissions.columns.submitted'))
                    ->datetime(__('tbtop-cms::form-submissions.date_format'))->muted(),
            ])
            ->tabs($this->tabs())
            ->filters($this->filters($s))
            ->defaultSort('created_at', 'desc')
            ->emptyState(
                __('tbtop-cms::form-submissions.empty.title'),
                __('tbtop-cms::form-submissions.empty.description'),
                'inbox',
            )
            ->rowActions([
                $this->viewAction($s),
                $this->retryAction($s),
                $this->markProcessedAction($s),
                $this->discardAction($s),
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
        $key = 'tbtop-cms::form-submissions.statuses.' . $value;
        $label = __($key);

        return $label === $key ? (string) $value : $label;
    }

    private function deliveryLabel(mixed $value): string
    {
        $key = 'tbtop-cms::form-submissions.delivery.' . $value;
        $label = __($key);

        return $label === $key ? (string) $value : $label;
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

    /**
     * Read-only view modal (5.3 fix): the old body was an editable keyvalue
     * FORM field (disabledIf'd, but still rendering add/remove-row controls
     * — see KeyvalueForm client-side, which has no true read-only mode).
     * displayKeyValue() is the DSL's read-only key/value block, but unlike
     * DisplayValueBlock it has no field()-style live-data binding — its
     * `entries` map bakes at author time (page-render time), before any
     * specific submission id is known. Since the payload's KEYS are
     * genuinely dynamic (host apps author custom form blocks with arbitrary
     * payload shapes — see the class docblock on LeadIdentity), there is no
     * fixed set of DisplayValueBlock::field() bindings that could cover it.
     *
     * The fix: flatten the payload into ONE pre-formatted multi-line string
     * server-side (in loadPayload(), where the real row is known) and bind
     * that single string via a field()-bound DisplayValueBlock — genuinely
     * read-only (no input, no add/remove buttons), unlike the old field.
     * The surrounding meta (page/locale/submitted/delivery/error) IS a fixed
     * set of keys, so each gets its own field()-bound DisplayValueBlock,
     * formatted the same way the table's own columns are.
     */
    private function viewAction(S $s): ActionBuilder
    {
        $body = $s->stack([
            $s->flex([
                $this->metaLabel($s, __('tbtop-cms::form-submissions.columns.page')),
                $s->displayValue('page')->field('page'),
            ], gap: 2),
            $s->flex([
                $this->metaLabel($s, __('tbtop-cms::form-submissions.columns.locale')),
                $s->displayValue('locale')->field('locale'),
            ], gap: 2),
            $s->flex([
                $this->metaLabel($s, __('tbtop-cms::form-submissions.columns.submitted')),
                $s->displayValue('submittedAt')->field('submittedAt'),
            ], gap: 2),
            $s->flex([
                $this->metaLabel($s, __('tbtop-cms::form-submissions.columns.delivery')),
                $s->displayValue('deliveryStatus')->field('deliveryStatus')->badge([
                    __('tbtop-cms::form-submissions.delivery.pending') => 'warning',
                    __('tbtop-cms::form-submissions.delivery.handled') => 'success',
                    __('tbtop-cms::form-submissions.delivery.failed') => 'danger',
                ]),
            ], gap: 2, align: 'center'),
            $s->displayText(__('tbtop-cms::form-submissions.fields.error'))->variant('muted'),
            $s->displayValue('error')->field('error'),
            $s->displayDivider(),
            $s->displayText(__('tbtop-cms::form-submissions.fields.payload'))->variant('muted'),
            $s->displayValue('payloadText')->field('payloadText'),
        ]);

        return $s->action('viewPayload')->label(__('tbtop-cms::form-submissions.actions.view'))
            ->modal(__('tbtop-cms::form-submissions.actions.view_title'), $body)
            ->query($this->loadPayload(...), needs: ['row']);
    }

    private function metaLabel(S $s, string $label): TextBlock
    {
        return $s->displayText($label)->variant('muted');
    }

    /** @return array<string, mixed> */
    private function loadPayload(ActionCtx $ctx): array
    {
        $submission = FormSubmission::query()->with('page.slugs')->findOrFail((int) ($ctx->row['id'] ?? 0));
        $this->markRead($submission);

        $page = $submission->page;

        return [
            'page' => $page !== null
                ? $this->formatPage([
                    'id' => $page->getKey(),
                    'name' => $page->getTranslations('name'),
                    'title' => $page->getTranslations('title'),
                    'slugs' => $page->slugs->map(static fn ($slug): array => ['path' => $slug->path])->all(),
                ])
                : __('tbtop-cms::form-submissions.values.page_deleted'),
            'locale' => $submission->locale,
            'submittedAt' => optional($submission->created_at)
                ->format(__('tbtop-cms::form-submissions.date_format')) ?? '',
            'deliveryStatus' => $this->deliveryLabel($submission->status->value),
            'error' => $submission->error ?? __('tbtop-cms::form-submissions.values.no_error'),
            'payloadText' => $this->formatPayloadText($submission->payload),
        ];
    }

    /**
     * One "key: value" pair per line — a plain string is the only read-only,
     * live-bindable shape available (see viewAction()'s docblock); this is
     * the closest visual equivalent to displayKeyValue's dt/dl rows that a
     * field()-bound DisplayValueBlock can carry.
     *
     * @param  array<string, mixed>  $payload
     */
    private function formatPayloadText(array $payload): string
    {
        if ($payload === []) {
            return __('tbtop-cms::form-submissions.values.empty_payload');
        }

        $lines = [];
        foreach ($payload as $key => $value) {
            $lines[] = $key . ': ' . self::stringifyValue($value);
        }

        return implode("\n", $lines);
    }

    /** Opening a submission clears its unread state. */
    private function markRead(FormSubmission $submission): void
    {
        if ($submission->lead_status === LeadStatus::New) {
            $submission->forceFill(['lead_status' => LeadStatus::Read])->save();
        }
    }

    private static function stringifyValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    private function retryAction(S $s): ActionBuilder
    {
        // hiddenIf evaluates client-side against the projected row, where
        // status is already the translated delivery label — compare to it,
        // not to the raw enum value.
        return $s->action('retry')->label(__('tbtop-cms::form-submissions.actions.retry'))
            ->handle($this->retry(...), needs: ['row'])
            ->hiddenIf('status', '!=', $this->deliveryLabel(SubmissionStatus::Failed->value));
    }

    private function retry(ActionCtx $ctx): Effects
    {
        $submission = FormSubmission::query()->findOrFail((int) ($ctx->row['id'] ?? 0));

        app(SubmissionRetrier::class)->retry($submission);
        $submission->refresh();

        return $submission->status === SubmissionStatus::Handled
            ? Effects::make()->notify(__('tbtop-cms::form-submissions.actions.retried'))->refreshTable()
            : Effects::make()->notify((string) $submission->error, 'error')->refreshTable();
    }

    /**
     * No Reopen action anywhere in this page (locked design: lifecycle only
     * moves forward — New→Read is the only automatic/implicit transition,
     * and Processed/Discarded are terminal from the UI's perspective; nothing
     * here writes lead_status back to New or Read once it has advanced past
     * them). hiddenIf compares the PROJECTED (translated) lead_status label,
     * same quirk retryAction() already documents for the delivery column.
     */
    private function markProcessedAction(S $s): ActionBuilder
    {
        return $s->action('markProcessed')->label(__('tbtop-cms::form-submissions.actions.mark_processed'))
            ->handle($this->markProcessed(...), needs: ['row'])
            ->hiddenIf('lead_status', 'in', [
                __('tbtop-cms::form-submissions.statuses.processed'),
                __('tbtop-cms::form-submissions.statuses.discarded'),
            ]);
    }

    private function markProcessed(ActionCtx $ctx): Effects
    {
        $this->applyLeadStatus([(int) ($ctx->row['id'] ?? 0)], LeadStatus::Processed);

        return Effects::make()->notify(__('tbtop-cms::form-submissions.actions.marked_processed'))->refreshTable();
    }

    private function discardAction(S $s): ActionBuilder
    {
        return $s->action('discard')->label(__('tbtop-cms::form-submissions.actions.discard'))
            ->confirm(
                __('tbtop-cms::form-submissions.actions.discard_title'),
                __('tbtop-cms::form-submissions.actions.discard_confirm'),
            )
            ->handle($this->discard(...), needs: ['row'])
            ->hiddenIf('lead_status', '=', __('tbtop-cms::form-submissions.statuses.discarded'));
    }

    private function discard(ActionCtx $ctx): Effects
    {
        $this->applyLeadStatus([(int) ($ctx->row['id'] ?? 0)], LeadStatus::Discarded);

        return Effects::make()->notify(__('tbtop-cms::form-submissions.actions.discarded'))->refreshTable();
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
        FormSubmission::query()->whereKey((int) ($ctx->row['id'] ?? 0))->delete();

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
        FormSubmission::query()->whereKey($ctx->selection)->delete();

        return Effects::make()->notify(__('tbtop-cms::form-submissions.actions.deleted'))->refreshTable();
    }

    /** @param  list<int|string>  $ids */
    private function applyLeadStatus(array $ids, LeadStatus $status): void
    {
        if ($ids === []) {
            return;
        }

        FormSubmission::query()->whereKey($ids)->update(['lead_status' => $status->value]);
    }
}
