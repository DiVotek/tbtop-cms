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
    private const int HEADLINE_MAX = 110;

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
            'headline' => self::headline($ctx->name),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $ctx->url],
        ];

        if ($ctx->author !== null) {
            $node['author'] = $ctx->author;
        }

        if ($ctx->publisher !== null) {
            $node['publisher'] = $ctx->publisher;
        }

        return [$node];
    }

    /**
     * Google truncates or ignores an Article headline past 110 characters,
     * so a longer name is cut at the last whole word that fits — a cut
     * mid-word reads as a typo in the rich result.
     */
    private static function headline(string $name): string
    {
        if (mb_strlen($name) <= self::HEADLINE_MAX) {
            return $name;
        }

        $cut = mb_substr($name, 0, self::HEADLINE_MAX + 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false ? mb_substr($cut, 0, $space) : mb_substr($name, 0, self::HEADLINE_MAX));
    }
}
