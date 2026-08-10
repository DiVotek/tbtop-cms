<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Support;

use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\SubmitContext;

final readonly class LocatedFormBlock
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public HandlesFormSubmission $block,
        public array $data,
        public SubmitContext $context,
    ) {}
}
