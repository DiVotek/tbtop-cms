<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Support\FormBlockLocator;
use Tbtop\Cms\Form\Support\LocatedFormBlock;
use Tbtop\Cms\Page\Models\Page;
use Throwable;

/**
 * Retry re-runs both delivery paths the original submit attempted, in the
 * same order and with the same rule: the block's own handleSubmit() (a
 * custom block may forward to a CRM, queue a job, etc — HandlesFormSubmission's
 * contract, unrelated to cms-owned notifications), then SubmissionNotifier's
 * bell/mail/telegram fan-out.
 *
 * - The handler throws: the row stays Failed with the handler's error, no
 *   notification goes out (admins are never told about a submission the
 *   block did not handle) and handled_at stays null.
 * - The handler succeeds (or already had): the fan-out runs to every enabled
 *   channel; Handled with no error if it succeeds, Failed with the channel
 *   error otherwise.
 *
 * The claim is released once, with that outcome.
 *
 * handleSubmit() is not idempotent, so it runs only while handled_at is
 * null: that column records the handler's own success (FormSubmitController
 * and this class set it the moment handleSubmit() returns), which lets a
 * retry for a failed fan-out skip the CRM/queue side effects that already
 * happened.
 */
class SubmissionRetrier
{
    public function __construct(
        private readonly FormBlockLocator $locator,
        private readonly SubmissionNotifier $notifier,
    ) {}

    public function retry(FormSubmission $submission): void
    {
        if (! SubmissionClaim::take($submission, SubmissionStatus::Failed)) {
            return;
        }

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

        if ($submission->handled_at === null) {
            try {
                $located->block->handleSubmit($submission->payload, $located->data, $located->context);
            } catch (Throwable $e) {
                $this->markFailed($submission, $e->getMessage());

                return;
            }

            $submission->forceFill(['handled_at' => now()])->save();
        }

        $errors = $this->notifier->deliver($submission, $page);

        if ($errors !== []) {
            $this->markFailed($submission, implode(' | ', $errors));

            return;
        }

        $this->finish($submission, SubmissionStatus::Handled, null);
    }

    private function markFailed(FormSubmission $submission, string $error): void
    {
        $this->finish($submission, SubmissionStatus::Failed, $error);
    }

    private function finish(FormSubmission $submission, SubmissionStatus $status, ?string $error): void
    {
        SubmissionClaim::release($submission, ['status' => $status->value, 'error' => $error]);
    }
}
