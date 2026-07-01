<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Cms\Page\Models\Page;

final readonly class SubmitContext
{
    public function __construct(
        public string $locale,
        public string $fallbackLocale,
        public ?Page $page = null,
    ) {}
}
