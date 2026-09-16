<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

use Tbtop\Cms\Frontend\Seo\Schema\OpenGraph;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeInterface;

/** A plain WebPage node with no Open Graph article semantics. */
abstract class WebPageFamily implements SchemaTypeInterface
{
    public function label(): string
    {
        return __('tbtop-cms::pages.schema_type.' . $this->key());
    }

    public function openGraph(SchemaContext $ctx): OpenGraph
    {
        return OpenGraph::website();
    }

    /** @return list<array<string, mixed>> */
    public function build(SchemaContext $ctx): array
    {
        return [$ctx->node($this->key())];
    }
}
