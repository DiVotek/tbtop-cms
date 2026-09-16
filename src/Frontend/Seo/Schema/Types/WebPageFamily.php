<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaType;

/** A plain WebPage node with no Open Graph article semantics. */
abstract class WebPageFamily extends SchemaType
{
    /** @return list<array<string, mixed>> */
    public function build(SchemaContext $ctx): array
    {
        return [$ctx->baseNode($this->key())];
    }
}
