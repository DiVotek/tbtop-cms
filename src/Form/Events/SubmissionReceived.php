<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Events;

use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Page\Models\Page;

/**
 * Raised after a form submission passes validation and is stored (or would
 * have been, had storage not been disabled via config), before delivery is
 * attempted. A typical subscriber forwards the lead to a CRM or sends an
 * internal notification.
 */
final class SubmissionReceived
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly ?FormSubmission $submission,
        public readonly array $payload,
        public readonly Page $page,
        public readonly string $blockId,
        public readonly string $locale,
    ) {}
}
