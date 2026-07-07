<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Frontend\Form\FormBlockLocator;
use Tbtop\Cms\Frontend\Form\LocatedFormBlock;
use Tbtop\Cms\Page\Models\Page;
use Throwable;

class SubmissionRetrier
{
    public function __construct(
        private readonly FormBlockLocator $locator,
    ) {}

    public function retry(FormSubmission $submission): void
    {
        $page = $submission->page_id !== null ? Page::query()->find($submission->page_id) : null;

        if (! $page instanceof Page) {
            $this->markFailed($submission, 'Page no longer exists.');

            return;
        }

        $located = $this->locator->locate($page, $submission->block_id);

        if (! $located instanceof LocatedFormBlock) {
            $this->markFailed($submission, 'Form block no longer exists on the page.');

            return;
        }

        try {
            $located->block->handleSubmit($submission->payload, $located->data, $located->context);
        } catch (Throwable $e) {
            $this->markFailed($submission, $e->getMessage());

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
