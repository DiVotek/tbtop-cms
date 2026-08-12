<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\Response;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Frontend\Seo\IndexingSetting;
use Tbtop\Cms\Frontend\Seo\SitemapEntryBuilder;
use Tbtop\Cms\Frontend\Seo\SitemapXmlRenderer;

class SitemapController
{
    private const string CACHE_KEY = 'tbtop-cms:sitemap-xml';

    public function __construct(
        private readonly SitemapEntryBuilder $entries,
        private readonly SitemapXmlRenderer $renderer,
        private readonly IndexingSetting $indexing,
        private readonly CmsCache $cache,
    ) {}

    public function __invoke(): Response
    {
        // Checked per-request, outside the cache: a 404 here must never be
        // served from (or written to) a still-warm cache entry.
        if (! $this->indexing->enabled()) {
            abort(404);
        }

        $xml = $this->cache->rememberLive(
            self::CACHE_KEY,
            fn (): string => $this->renderer->render($this->entries->build()),
        );

        return response()->make($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
