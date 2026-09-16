<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

use Tbtop\Cms\Frontend\Seo\Schema\OpenGraph;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeInterface;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageUrl;

class CollectionPage implements SchemaTypeInterface
{
    /**
     * How many children a CollectionPage advertises in hasPart. Sized to a
     * typical listing page rather than to the category: the point of the
     * property is to describe what this page holds, not to mirror the whole
     * subtree into every response.
     */
    private const int MAX_PARTS = 100;

    public function __construct(private readonly PageUrl $url) {}

    public function key(): string
    {
        return 'CollectionPage';
    }

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
        $node = $ctx->node($this->key());
        $parts = $this->parts($ctx->page, $ctx->locale);

        if ($parts !== []) {
            $node['hasPart'] = $parts;
        }

        return [$node];
    }

    /**
     * hasPart lists the category's live children — the listing a
     * CollectionPage exists to describe. Filtered through scopeLive() (the
     * single source of frontend visibility, schedule included) so a draft or
     * expired child is never advertised, and eager-loads slugs because
     * pathFor() reads the loaded relation.
     *
     * Capped at MAX_PARTS: a category with thousands of articles would
     * otherwise load every one of them on every render and inline them into
     * the page's HTML — the listing itself is paginated, and a crawler
     * discovers the rest by following it.
     *
     * @return list<array{'@type': string, name: string, url: string}>
     */
    private function parts(Page $page, string $locale): array
    {
        $children = $page->children()
            ->live()
            ->where('is_indexable', true)
            ->with('slugs')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::MAX_PARTS)
            ->get();

        $parts = [];

        foreach ($children as $child) {
            $name = (string) $child->getTranslation('title', $locale, false)
                ?: (string) $child->getTranslation('name', $locale, false);
            $path = $child->pathFor($locale);

            if ($name === '' || $path === null) {
                continue;
            }

            // Through PageUrl, same as canonical/alternates/breadcrumbs and
            // the sitemap: a bare url($path) drops the locale prefix.
            $parts[] = [
                '@type' => 'WebPage',
                'name' => $name,
                'url' => url($this->url->build($path, $locale)),
            ];
        }

        return $parts;
    }
}
