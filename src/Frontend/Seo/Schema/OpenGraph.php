<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

/** The Open Graph facts a SchemaTypeInterface contributes to the `seo` prop. */
final readonly class OpenGraph
{
    public function __construct(
        public string $type,
        public ?string $publishedTime = null,
        public ?string $modifiedTime = null,
        public ?string $author = null,
    ) {}

    public static function website(): self
    {
        return new self('website');
    }
}
