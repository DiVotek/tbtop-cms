<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Notifications;

use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Support\LeadIdentity;
use Tbtop\Cms\Form\Support\SubmissionFieldFormatter;
use Tbtop\Cms\Page\Models\Page;

final class FormSubmissionPayloadFactory
{
    public static function make(FormSubmission $submission, Page $page): FormSubmissionPayload
    {
        return new FormSubmissionPayload(
            leadIdentity: LeadIdentity::label($submission->payload) ?? __('tbtop-cms::form-submissions.values.lead_unknown'),
            pageName: self::pageName($page),
            locale: $submission->locale,
            submittedAt: self::submittedAt($submission),
            fields: SubmissionFieldFormatter::formatAll($submission->payload),
            actionUrl: route('tbtop.cms.form-submission-page', ['submission' => $submission->id]),
            actionLabel: __('tbtop-cms::notifications.bell.view'),
        );
    }

    private static function pageName(Page $page): string
    {
        $name = $page->name;

        return is_string($name) && trim($name) !== '' ? $name : __('tbtop-cms::form-submissions.values.page_number', ['id' => $page->getKey()]);
    }

    private static function submittedAt(FormSubmission $submission): string
    {
        $createdAt = $submission->created_at ?? now();

        return $createdAt->format(__('tbtop-cms::form-submissions.date_format'));
    }
}
