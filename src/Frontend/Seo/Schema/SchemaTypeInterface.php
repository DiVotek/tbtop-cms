<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

interface SchemaTypeInterface
{
    /** The schema.org @type, also the registry key. */
    public function key(): string;

    public function label(): string;

    /** Open Graph og:type; null means 'website'. */
    public function ogType(): ?string;

    /** @return list<array<string, mixed>> */
    public function build(SchemaContext $ctx): array;
}
