<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\DisplayValueBlock;
use Tbtop\Admin\Dsl\FormBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Support\AuthorNames;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Models\FormSubmissionComment;
use Tbtop\Cms\Form\SubmissionRetrier;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Form\Support\LeadIdentity;
use Tbtop\Cms\Page\Models\Page;

/**
 * Per-record submission page (Inbox rework part 1): payload, delivery status,
 * free lead-status transitions, and a comments thread. Replaces the old
 * viewPayload modal — see FormSubmissionsPage, whose row click now routes
 * here instead of opening that modal.
 */
class FormSubmissionPage extends AdminPage
{
    private ?FormSubmission $submissionCache = null;

    public static function path(): string
    {
        return 'form-submissions/{submission}';
    }

    /**
     * Reached only via a row click on the list page — no nav entry of its own.
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
        return LeadIdentity::label($this->submission()->payload) ?? $this->submission()->kind;
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::chrome.inbox.label'), 'url' => route('tbtop.cms.form-submissions-page')],
            ['label' => $this->title()],
        ];
    }

    public function view(S $s): Node
    {
        $submission = $this->submission();
        $this->markRead($submission);

        return $s->stack([
            $this->metaSection($s, $submission),
            $this->payloadSection($s, $submission),
            $this->statusSection($s, $submission),
            $this->commentsSection($s, $submission),
            $this->footer($s),
        ]);
    }

    /** No form to save on this page (status changes and comments are their own actions) — just back + delete. */
    private function footer(S $s): Node
    {
        $back = $s->action('back')->label(__('tbtop-cms::form-submissions.actions.close'))
            ->url(route('tbtop.cms.form-submissions-page'));

        return $s->flex([$this->deleteAction($s), $s->spacer(), $back], justify: 'end');
    }

    private function metaSection(S $s, FormSubmission $submission): Node
    {
        $page = $submission->page;
        $rows = [
            $this->metaRow($s, __('tbtop-cms::form-submissions.columns.page'), $this->formatPage($page)),
            $this->metaRow($s, __('tbtop-cms::form-submissions.columns.locale'), $submission->locale),
            $this->metaRow($s, __('tbtop-cms::form-submissions.columns.submitted'), $this->formatSubmittedAt($submission)),
            $this->metaRow(
                $s,
                __('tbtop-cms::form-submissions.columns.delivery'),
                $s->displayValue($this->deliveryLabel($submission->status))->badge([
                    __('tbtop-cms::form-submissions.delivery.pending') => ['color' => 'warning'],
                    __('tbtop-cms::form-submissions.delivery.handled') => ['color' => 'success'],
                    __('tbtop-cms::form-submissions.delivery.failed') => ['color' => 'danger'],
                ]),
            ),
        ];

        if ($submission->status === SubmissionStatus::Failed) {
            $rows[] = $this->metaRow($s, __('tbtop-cms::form-submissions.fields.error'), (string) $submission->error);
        }

        return $s->section(['title' => $this->title(), 'variant' => 'card'], [
            $s->stack($rows),
            ...(SubmissionRetrier::isRetryable($submission) ? [$this->retryAction($s)] : []),
        ]);
    }

    private function metaRow(S $s, string $label, mixed $value): Node
    {
        $valueNode = $value instanceof DisplayValueBlock ? $value : $s->displayValue((string) $value);

        return $s->flex([
            $s->displayText($label)->variant('muted'),
            $valueNode,
        ], gap: 2, align: 'center');
    }

    private function formatPage(?Page $page): string
    {
        if ($page === null) {
            return __('tbtop-cms::form-submissions.values.page_deleted');
        }

        $name = $this->firstLocaleValue($page->getTranslations('name'))
            ?? $this->firstLocaleValue($page->getTranslations('title'));

        return $name ?? __('tbtop-cms::form-submissions.values.page_number', ['id' => $page->getKey()]);
    }

    /** @param  array<string, mixed>|string|null  $raw */
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

    private function formatSubmittedAt(FormSubmission $submission): string
    {
        return optional($submission->created_at)->format(__('tbtop-cms::form-submissions.date_format')) ?? '';
    }

    private function deliveryLabel(SubmissionStatus $status): string
    {
        return __('tbtop-cms::form-submissions.delivery.' . $status->value);
    }

    /**
     * One field-per-payload-entry rendering (each entry gets its own labeled
     * displayValue row) — adapts formatPayloadText's "key: value" heuristic
     * from FormSubmissionsPage's now-removed modal, split into distinct rows
     * instead of one pre-joined multi-line string, since this page has no
     * modal-data field() binding to route a single string through.
     */
    private function payloadSection(S $s, FormSubmission $submission): Node
    {
        $payload = $submission->payload;

        if ($payload === []) {
            return $s->section(['title' => __('tbtop-cms::form-submissions.fields.payload'), 'variant' => 'card'], [
                $s->displayText(__('tbtop-cms::form-submissions.values.empty_payload'))->variant('muted'),
            ]);
        }

        $rows = [];
        foreach ($payload as $key => $value) {
            $rows[] = $this->metaRow($s, (string) $key, self::stringifyValue($value));
        }

        return $s->section(['title' => __('tbtop-cms::form-submissions.fields.payload'), 'variant' => 'card'], [
            $s->stack($rows),
        ]);
    }

    private static function stringifyValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * Free transitions in both directions (the previous one-way-only design
     * is reversed for this rework): one button per LeadStatus case, the
     * current status's own button simply omitted, since there is nothing
     * useful to click there.
     */
    private function statusSection(S $s, FormSubmission $submission): Node
    {
        $buttons = [];
        foreach (LeadStatus::cases() as $status) {
            if ($status === $submission->lead_status) {
                continue;
            }
            $buttons[] = $this->statusAction($s, $status);
        }

        return $s->section(['title' => __('tbtop-cms::form-submissions.status_section.title'), 'variant' => 'card'], [
            $s->flex($buttons, gap: 2, wrap: true),
        ]);
    }

    private function statusAction(S $s, LeadStatus $status): ActionBuilder
    {
        $action = $s->action('setStatus' . ucfirst($status->value))
            ->label(__('tbtop-cms::form-submissions.status_section.set_to', [
                'status' => __('tbtop-cms::form-submissions.statuses.' . $status->value),
            ]))
            ->handle(fn (ActionCtx $ctx): Effects => $this->setStatus($status));

        return $status === LeadStatus::Discarded
            ? $action->color('danger')->confirm(
                __('tbtop-cms::form-submissions.actions.discard_title'),
                __('tbtop-cms::form-submissions.actions.discard_confirm'),
            )
            : $action;
    }

    private function setStatus(LeadStatus $status): Effects
    {
        $submission = $this->submission();
        $old = $submission->lead_status->value;
        $submission->forceFill(['lead_status' => $status])->save();
        $this->logSubmission($submission, 'status_changed', [
            'attributes' => ['lead_status' => $status->value],
            'old' => ['lead_status' => $old],
        ]);

        // Back to New must leave the page: re-rendering the detail page would
        // immediately mark the submission Read again (markRead on open), so
        // the transition lands on the list instead.
        $target = $status === LeadStatus::New
            ? route('tbtop.cms.form-submissions-page')
            : route('tbtop.cms.form-submission-page', ['submission' => $submission->id]);

        return Effects::make()
            ->notify(__('tbtop-cms::form-submissions.status_section.updated', [
                'status' => __('tbtop-cms::form-submissions.statuses.' . $status->value),
            ]))
            ->redirect($target);
    }

    private function retryAction(S $s): ActionBuilder
    {
        return $s->action('retry')->label(__('tbtop-cms::form-submissions.actions.retry'))
            ->handle(fn (ActionCtx $ctx): Effects => $this->retry());
    }

    private function retry(): Effects
    {
        $submission = $this->submission();
        $target = route('tbtop.cms.form-submission-page', ['submission' => $submission->id]);

        app(SubmissionRetrier::class)->retry($submission);
        $submission->refresh();

        // The action is only offered on a Failed row, but retry() still
        // no-ops when a concurrent retry (a double click) won the claim:
        // nothing ran, so nothing to audit.
        if (in_array($submission->status, [SubmissionStatus::Pending, SubmissionStatus::Processing], true)) {
            return Effects::make()->notify(__('tbtop-cms::form-submissions.actions.already_retrying'), 'info')->redirect($target);
        }

        $this->logSubmission(
            $submission,
            'delivery_retried',
            context: ['result' => $submission->status->value],
        );

        $effects = $submission->status === SubmissionStatus::Handled
            ? Effects::make()->notify(__('tbtop-cms::form-submissions.actions.retried'))
            : Effects::make()->notify((string) $submission->error, 'error');

        return $effects->redirect($target);
    }

    private function commentsSection(S $s, FormSubmission $submission): Node
    {
        $comments = $submission->comments;
        $authors = AuthorNames::lookup($comments->pluck('user_id'));

        $list = $comments->isEmpty()
            ? [$s->displayText(__('tbtop-cms::form-submissions.comments.empty'))->variant('muted')]
            : $comments->map(fn (FormSubmissionComment $comment): Node => $this->commentRow($s, $comment, $authors))->all();

        return $s->section(['title' => __('tbtop-cms::form-submissions.comments.title'), 'variant' => 'card'], [
            $s->stack($list),
            $s->displayDivider(),
            $this->commentForm($s, $submission),
        ]);
    }

    /** @param  Collection<int|string, Model>  $authors */
    private function commentRow(S $s, FormSubmissionComment $comment, Collection $authors): Node
    {
        $author = AuthorNames::label($comment->user_id, $authors)
            ?? __('tbtop-cms::form-submissions.comments.unknown_author', ['id' => $comment->user_id]);
        $timestamp = optional($comment->created_at)->format(__('tbtop-cms::form-submissions.date_format')) ?? '';

        return $s->stack([
            $s->flex([
                $s->displayText($author)->variant('subheading'),
                $s->displayText($timestamp)->variant('muted'),
            ], gap: 2, align: 'center'),
            $s->displayText($comment->body),
        ], ['gap' => 1]);
    }

    private function commentForm(S $s, FormSubmission $submission): FormBuilder
    {
        $submit = $s->action('addComment')->label(__('tbtop-cms::form-submissions.comments.submit'))
            ->color('primary')->submit('addCommentForm');

        return $s->form('addCommentForm', [
            $s->textarea('body')
                ->label(__('tbtop-cms::form-submissions.comments.label'))
                ->placeholder(__('tbtop-cms::form-submissions.comments.placeholder'))
                ->required()
                ->maxLength(5000),
            $s->flex([$s->spacer(), $submit], justify: 'end'),
        ])->onSubmit(fn (ActionCtx $ctx): Effects => $this->addComment($ctx, $submission));
    }

    private function addComment(ActionCtx $ctx, FormSubmission $submission): Effects
    {
        $comment = FormSubmissionComment::query()->create([
            'submission_id' => $submission->id,
            'user_id' => CurrentAdmin::user()?->getKey(),
            'body' => (string) ($ctx->form['body'] ?? ''),
        ]);
        $this->logSubmission($submission, 'comment_added', context: ['comment_id' => $comment->getKey()]);

        return Effects::make()
            ->notify(__('tbtop-cms::form-submissions.comments.added'))
            ->redirect(route('tbtop.cms.form-submission-page', ['submission' => $submission->id]));
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return $s->action('deleteSubmission')->label(__('tbtop-cms::form-submissions.actions.delete'))
            ->color('danger')
            ->confirm(
                __('tbtop-cms::form-submissions.actions.delete_title'),
                __('tbtop-cms::form-submissions.actions.delete_confirm'),
            )
            ->handle(fn (ActionCtx $ctx): Effects => $this->delete());
    }

    private function delete(): Effects
    {
        $submission = $this->submission();
        $submission->delete();
        $this->logSubmission($submission, 'deleted');

        return Effects::make()
            ->notify(__('tbtop-cms::form-submissions.actions.deleted'))
            ->redirect(route('tbtop.cms.form-submissions-page'));
    }

    /** Opening the page clears the New unread state — same semantics as the old modal's markRead(). */
    private function markRead(FormSubmission $submission): void
    {
        if ($submission->lead_status === LeadStatus::New) {
            $submission->forceFill(['lead_status' => LeadStatus::Read])->save();
            $this->logSubmission($submission, 'status_changed', [
                'attributes' => ['lead_status' => LeadStatus::Read->value],
                'old' => ['lead_status' => LeadStatus::New->value],
            ]);
        }
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

    private function submission(): FormSubmission
    {
        return $this->submissionCache ??= FormSubmission::query()
            ->with(['page', 'comments'])
            ->findOrFail($this->submissionId());
    }

    private function submissionId(): int
    {
        $id = request()->route('submission');

        return is_string($id) ? (int) $id : 0;
    }
}
