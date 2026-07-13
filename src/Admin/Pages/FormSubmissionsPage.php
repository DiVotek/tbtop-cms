<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionRetrier;
use Tbtop\Cms\Form\SubmissionStatus;

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
            ->defaultSort('created_at', 'desc')
            ->emptyState(
                __('tbtop-cms::form-submissions.empty.title'),
                __('tbtop-cms::form-submissions.empty.description'),
                'inbox',
            )
            ->rowActions([$this->viewAction($s), $this->retryAction($s)]);

        return $s->stack([$table]);
    }

    /** @return Builder<FormSubmission> */
    private function query(): Builder
    {
        return FormSubmission::query()->with('page.slugs');
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

    private function viewAction(S $s): ActionBuilder
    {
        $close = $s->action('viewPayloadClose')->label(__('tbtop-cms::form-submissions.actions.close'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());

        $form = $s->form('submissionPayload', [
            $s->keyvalue('payload')->label(__('tbtop-cms::form-submissions.fields.payload'))
                ->disabledIf('payload', 'not empty'),
            $s->flex([$s->spacer(), $close], justify: 'end'),
        ]);

        return $s->action('viewPayload')->label(__('tbtop-cms::form-submissions.actions.view'))
            ->modal(__('tbtop-cms::form-submissions.actions.view_title'), $form)
            ->query($this->loadPayload(...), needs: ['row']);
    }

    /** @return array<string, mixed> */
    private function loadPayload(ActionCtx $ctx): array
    {
        $submission = FormSubmission::query()->findOrFail((int) ($ctx->row['id'] ?? 0));
        $this->markRead($submission);

        return ['payload' => array_map(self::stringifyValue(...), $submission->payload)];
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
        return is_scalar($value) ? (string) $value : json_encode($value);
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
}
