<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Illuminate\Support\Facades\Context;
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

    /**
     * Any exception after the claim — the page or block lookup, the
     * handled_at write, the fan-out — ends the row Failed with its message
     * and goes to report(); it is never rethrown, so the admin retry action
     * shows the error toast instead of a 500.
     */
    public function retry(FormSubmission $submission): void
    {
        if (! SubmissionClaim::take($submission, SubmissionStatus::Failed)) {
            return;
        }

        // Not null: if even report() throws, the row must not be released Handled.
        $error = 'Retry did not finish.';

        try {
            $error = $this->run($submission);
        } catch (Throwable $e) {
            // The original exception, so host trackers group and filter it by its
            // own type; the id rides along in the log context.
            Context::add('submission_id', $submission->id);
            report($e);
            Context::forget('submission_id');

            $error = $e->getMessage();
        } finally {
            SubmissionClaim::release($submission, [
                'status' => ($error === null ? SubmissionStatus::Handled : SubmissionStatus::Failed)->value,
                'error' => $error,
            ]);
        }
    }

    /** @return string|null the error the row is released Failed with, null for Handled */
    private function run(FormSubmission $submission): ?string
    {
        $page = $submission->page_id !== null ? Page::query()->find($submission->page_id) : null;

        if (! $page instanceof Page) {
            return 'Page no longer exists.';
        }

        $located = $this->locator->locate($page, $submission->block_id, $submission->locale);

        if (! $located instanceof LocatedFormBlock) {
            return 'Form block no longer exists on the page.';
        }

        if ($submission->handled_at === null) {
            try {
                $located->block->handleSubmit($submission->payload, $located->data, $located->context);
            } catch (Throwable $e) {
                return $e->getMessage();
            }

            $submission->forceFill(['handled_at' => now()])->save();
        }

        $errors = $this->notifier->deliver($submission, $page);

        return $errors === [] ? null : implode(' | ', $errors);
    }
}
