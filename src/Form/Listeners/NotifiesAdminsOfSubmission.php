<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Listeners;

use Illuminate\Contracts\Foundation\Application;
use Tbtop\Cms\Form\Events\SubmissionReceived;
use Tbtop\Cms\Form\SubmissionNotifier;
use Tbtop\Cms\Form\SubmissionStatus;

/**
 * Runs the bell/mail/telegram fan-out for every stored submission, deferred
 * until after the visitor's response is sent — this package ships no queue
 * requirement (presentation sites run without workers), so
 * app()->terminating() is the only "don't make the visitor wait on an SMTP
 * or Telegram round-trip" mechanism available without one. The controller
 * still writes the submission as Pending synchronously; this callback is
 * what later flips it to Handled/Failed (see SubmissionNotifier).
 *
 * A null submission (tbtop-cms.forms.store disabled) has no row to track
 * delivery status against, so it's skipped — nothing here can be retried
 * or linked to from a bell notification without one.
 */
final class NotifiesAdminsOfSubmission
{
    public function __construct(private readonly Application $app) {}

    public function handle(SubmissionReceived $event): void
    {
        $submission = $event->submission;

        if ($submission === null) {
            return;
        }

        $page = $event->page;

        $this->app->terminating(function () use ($submission, $page): void {
            // Preserve a non-pending state if another process handled or
            // failed this submission before the deferred callback runs.
            $submission->refresh();

            if ($submission->status !== SubmissionStatus::Pending) {
                return;
            }

            $this->app->make(SubmissionNotifier::class)->notify($submission, $page);
        });
    }
}
