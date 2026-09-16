<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

/**
 * A host implements this by extending SchemaType, not this interface
 * directly — see that class for the defaults it provides. The planned
 * Block::toSchema() contract will receive this same SchemaContext, so
 * schema code written against it is reusable there.
 */
interface SchemaTypeInterface
{
    /** The schema.org @type, also the registry key. */
    public function key(): string;

    public function label(): string;

    public function openGraph(SchemaContext $ctx): OpenGraph;

    /** @return list<array<string, mixed>> */
    public function build(SchemaContext $ctx): array;
}
