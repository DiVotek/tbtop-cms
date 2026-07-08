<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

/**
 * Where a submission sits in the manager's workflow — independent of mail
 * delivery ({@see SubmissionStatus}). Only `New` counts toward the inbox
 * badge.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Processed = 'processed';
    case Discarded = 'discarded';
}
