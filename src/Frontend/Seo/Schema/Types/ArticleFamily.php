<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeInterface;

/**
 * `author` is not filled in here: it comes from the site identity settings,
 * not per-page data, so SeoResolver::jsonLd() merges it in after build()
 * returns — the one place that already parses identity.
 */
abstract class ArticleFamily implements SchemaTypeInterface
{
    public function label(): string
    {
        return __('tbtop-cms::pages.schema_type.' . $this->key());
    }

    public function ogType(): ?string
    {
        return 'article';
    }

    /** @return list<array<string, mixed>> */
    public function build(SchemaContext $ctx): array
    {
        return [[
            ...$ctx->node($this->key()),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $ctx->url],
        ]];
    }
}
