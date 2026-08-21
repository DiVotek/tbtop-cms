<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Support\FormBlockLocator;
use Tbtop\Cms\Form\Support\LocatedFormBlock;
use Tbtop\Cms\Page\Models\Page;
use Throwable;

/** Retry only the independently persisted delivery branches that have not succeeded. */
class SubmissionRetrier
{
    public function __construct(
        private readonly FormBlockLocator $locator,
        private readonly SubmissionNotifier $notifier,
    ) {}

    public function retry(FormSubmission $submission): void
    {
        $claimed = FormSubmission::query()
            ->whereKey($submission->getKey())
            ->where('status', SubmissionStatus::Failed->value)
            ->update(['status' => SubmissionStatus::Pending->value]);

        if ($claimed !== 1) {
            return;
        }

        $submission->refresh();
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

        if ($submission->handler_handled_at === null) {
            try {
                $located->block->handleSubmit($submission->payload, $located->data, $located->context);
                $submission->markHandlerHandled();
            } catch (Throwable $e) {
                $submission->markHandlerFailed($e->getMessage());
            }
        }

        if ($submission->notification_handled_at === null) {
            $this->notifier->notify($submission, $page);
        }

        $submission->refresh()->syncDeliveryStatus();
    }

    private function markFailed(FormSubmission $submission, string $error): void
    {
        $submission->forceFill([
            'status' => SubmissionStatus::Failed,
            'error' => $error,
        ])->save();
    }
}
