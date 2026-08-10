<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Frontend\Form\FormBlockLocator;
use Tbtop\Cms\Frontend\Form\LocatedFormBlock;
use Tbtop\Cms\Page\Models\Page;
use Throwable;

/**
 * Retry has two independent deliveries to re-run, both of which the original
 * submit attempted: the block's own handleSubmit() (a custom block may
 * forward to a CRM, queue a job, etc — HandlesFormSubmission's contract,
 * unrelated to cms-owned notifications) and SubmissionNotifier's bell/mail/
 * telegram fan-out. Both run; the submission is Handled only if both
 * succeed, Failed with both errors joined if either doesn't — a retry that
 * silently drops one of the two original delivery paths would be a
 * regression for any block that relies on handleSubmit() for its own
 * delivery, not just the notification rework.
 */
class SubmissionRetrier
{
    public function __construct(
        private readonly FormBlockLocator $locator,
        private readonly SubmissionNotifier $notifier,
    ) {}

    public function retry(FormSubmission $submission): void
    {
        $page = $submission->page_id !== null ? Page::query()->find($submission->page_id) : null;

        if (! $page instanceof Page) {
            $this->markFailed($submission, 'Page no longer exists.');

            return;
        }

        $located = $this->locator->locate($page, $submission->block_id, $submission->locale);

        if (! $located instanceof LocatedFormBlock) {
            $this->markFailed($submission, 'Form block no longer exists on the page.');

            return;
        }

        $errors = [];

        try {
            $located->block->handleSubmit($submission->payload, $located->data, $located->context);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $this->notifier->notify($submission, $page);
        $submission->refresh();

        if ($submission->status === SubmissionStatus::Failed) {
            $errors[] = (string) $submission->error;
        }

        if ($errors !== []) {
            $this->markFailed($submission, implode(' | ', $errors));

            return;
        }

        $submission->forceFill([
            'status' => SubmissionStatus::Handled,
            'handled_at' => now(),
            'error' => null,
        ])->save();
    }

    private function markFailed(FormSubmission $submission, string $error): void
    {
        $submission->forceFill([
            'status' => SubmissionStatus::Failed,
            'error' => $error,
        ])->save();
    }
}
