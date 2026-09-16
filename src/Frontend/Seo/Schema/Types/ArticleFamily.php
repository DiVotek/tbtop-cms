<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

use Tbtop\Cms\Frontend\Seo\Schema\OpenGraph;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaType;

/**
 * `author` is not filled in here: it comes from the site identity settings,
 * not per-page data, so SchemaContext::$author carries it in — the one place
 * that already parses identity.
 */
abstract class ArticleFamily extends SchemaType
{
    public function openGraph(SchemaContext $ctx): OpenGraph
    {
        return new OpenGraph(
            type: 'article',
            publishedTime: $ctx->publishedAt(),
            modifiedTime: $ctx->modifiedAt(),
            author: $ctx->author['name'] ?? null,
        );
    }

    /** @return list<array<string, mixed>> */
    public function build(SchemaContext $ctx): array
    {
        $node = [
            ...$ctx->baseNode($this->key()),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $ctx->url],
        ];

        if ($ctx->author !== null) {
            $node['author'] = $ctx->author;
        }

        return [$node];
    }
}
