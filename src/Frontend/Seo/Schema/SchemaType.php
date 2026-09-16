<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

use Illuminate\Support\Str;

/**
 * Sensible defaults for a schema.org node type, same convention as
 * Block: a host implementing its own type extends this rather than
 * SchemaTypeInterface directly, so it only has to override what it cares
 * about. label() and openGraph() are the two a host is unlikely to need —
 * key() and build() are what actually make the type.
 */
abstract class SchemaType implements SchemaTypeInterface
{
    abstract public function key(): string;

    /** @return list<array<string, mixed>> */
    abstract public function build(SchemaContext $ctx): array;

    public function label(): string
    {
        return Str::headline($this->key());
    }

    public function openGraph(SchemaContext $ctx): OpenGraph
    {
        return OpenGraph::website();
    }
}
