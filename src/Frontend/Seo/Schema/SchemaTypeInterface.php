<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

interface SchemaTypeInterface
{
    /** The schema.org @type, also the registry key. */
    public function key(): string;

    public function label(): string;

    public function openGraph(SchemaContext $ctx): OpenGraph;

    /** @return list<array<string, mixed>> */
    public function build(SchemaContext $ctx): array;
}
