<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

/**
 * One `- [name](url): notes` line of an llms.txt file list. A relative URL
 * is made absolute against the site when the file is rendered.
 */
final readonly class LlmsTxtLink
{
    public function __construct(
        public string $name,
        public string $url,
        public ?string $notes = null,
    ) {}
}
