<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use RuntimeException;
use Throwable;

/**
 * Wraps an unexpected exception from a submission's notify or retry run so
 * report() carries the submission id (context(), and the message) to the
 * host's error tracker; the original exception is the previous one. The
 * caller has already written the submission Failed, so this is reported,
 * never thrown.
 */
final class SubmissionDeliveryFailed extends RuntimeException
{
    public function __construct(private readonly int $submissionId, Throwable $previous)
    {
        parent::__construct("Form submission [{$submissionId}] delivery failed: {$previous->getMessage()}", 0, $previous);
    }

    /** @return array{submission_id: int} */
    public function context(): array
    {
        return ['submission_id' => $this->submissionId];
    }
}
