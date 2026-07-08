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
        return 'Form submissions';
    }

    public function view(S $s): Node
    {
        $table = $s->table('formSubmissions')
            ->query($this->query(...))
            ->columns([
                Column::make('lead_status')->label('Status')->badge([
                    LeadStatus::New->value => Color::Danger,
                    LeadStatus::Read->value => Color::Info,
                    LeadStatus::Processed->value => Color::Success,
                    LeadStatus::Discarded->value => Color::Gray,
                ]),
                Column::make('status')->label('Delivery')->badge([
                    SubmissionStatus::Pending->value => Color::Warning,
                    SubmissionStatus::Handled->value => Color::Success,
                    SubmissionStatus::Failed->value => Color::Danger,
                ]),
                Column::make('kind')->label('Form'),
                Column::make('page')->label('Page')->formatUsing($this->formatPage(...)),
                Column::make('locale')->label('Locale'),
                Column::make('created_at')->label('Submitted')->datetime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyState('No submissions yet', 'Form submissions will appear here.', 'inbox')
            ->rowActions([$this->viewAction($s), $this->retryAction($s)]);

        return $s->stack([$table]);
    }

    /** @return Builder<FormSubmission> */
    private function query(): Builder
    {
        return FormSubmission::query()->with('page.slugs');
    }

    /** @param  array<string, mixed>|null  $page */
    private function formatPage(?array $page): string
    {
        if ($page === null) {
            return '(page deleted)';
        }

        $name = $this->firstLocaleValue($page['name'] ?? null)
            ?? $this->firstLocaleValue($page['title'] ?? null);
        if ($name !== null) {
            return $name;
        }

        $path = $page['slugs'][0]['path'] ?? null;

        return $path !== null ? (string) $path : "Page #{$page['id']}";
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
        $form = $s->form('submissionPayload', [
            $s->keyvalue('payload')->label('Payload')->disabledIf('payload', 'not empty'),
            $s->actionsRow([
                $s->action('viewPayloadClose')->label('Close')
                    ->handle(static fn (): Effects => Effects::make()->closeModal()),
            ]),
        ]);

        return $s->action('viewPayload')->label('View payload')
            ->modal('Submission payload', $form)
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
        return $s->action('retry')->label('Retry')
            ->handle($this->retry(...), needs: ['row'])
            ->hiddenIf('status', '!=', SubmissionStatus::Failed->value);
    }

    private function retry(ActionCtx $ctx): Effects
    {
        $submission = FormSubmission::query()->findOrFail((int) ($ctx->row['id'] ?? 0));

        app(SubmissionRetrier::class)->retry($submission);
        $submission->refresh();

        return $submission->status === SubmissionStatus::Handled
            ? Effects::make()->notify('Submission retried successfully')->refreshTable()
            : Effects::make()->notify((string) $submission->error, 'error')->refreshTable();
    }
}
