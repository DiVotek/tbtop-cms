<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Tbtop\Cms\Form\Models\FormSubmission;

/**
 * Compare-and-swap over a submission's status, so the side effects behind it
 * run exactly once no matter how many requests race for the same row.
 *
 * The claim is the conditional UPDATE itself: the database decides the
 * winner, and an affected-row count other than 1 means someone else already
 * took the row. Callers must not pre-check the status and then act — that
 * reintroduces the race this closes.
 */
final class SubmissionClaim
{
    /** True only for the caller that moved the row out of $from. */
    public static function take(FormSubmission $submission, SubmissionStatus $from): bool
    {
        $claimed = FormSubmission::query()
            ->whereKey($submission->getKey())
            ->where('status', $from->value)
            ->update(['status' => SubmissionStatus::Processing->value]);

        if ($claimed !== 1) {
            return false;
        }

        $submission->refresh();

        return true;
    }

    /**
     * Releases a claimed row. Guarded on Processing so a release can never
     * overwrite a status someone else has since set.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function release(FormSubmission $submission, array $attributes): void
    {
        FormSubmission::query()
            ->whereKey($submission->getKey())
            ->where('status', SubmissionStatus::Processing->value)
            ->update($attributes);

        $submission->refresh();
    }
}
