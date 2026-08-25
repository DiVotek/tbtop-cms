<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\Response;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Frontend\Seo\IndexingSetting;
use Tbtop\Cms\Frontend\Seo\SitemapEntryBuilder;
use Tbtop\Cms\Frontend\Seo\SitemapXmlRenderer;

/**
 * sitemap.xml is an index pointing at sitemap-N.xml chunks, always — even on
 * a site small enough to fit one chunk.
 *
 * A site that outgrows a single file would otherwise have to change the shape
 * of the document a crawler already fetched, and the shape is what tells it
 * whether to expect URLs or more sitemaps. Serving an index from the start
 * means growth adds entries rather than replacing the document.
 */
class SitemapController
{
    private const string INDEX_KEY = 'tbtop-cms:sitemap-index';

    private const string CHUNK_KEY = 'tbtop-cms:sitemap-chunk:';

    public function __construct(
        private readonly SitemapEntryBuilder $entries,
        private readonly SitemapXmlRenderer $renderer,
        private readonly IndexingSetting $indexing,
        private readonly CmsCache $cache,
    ) {}

    public function index(): Response
    {
        $this->guard();

        $xml = $this->cache->rememberLive(self::INDEX_KEY . ':' . $this->originKey(), function (): string {
            $count = $this->entries->chunkCount();

            $locs = [];
            for ($chunk = 1; $chunk <= $count; $chunk++) {
                $locs[] = route('tbtop-cms.sitemap.chunk', ['chunk' => $chunk]);
            }

            return $this->renderer->renderIndex($locs, now()->toAtomString());
        });

        return $this->xml($xml);
    }

    public function chunk(int $chunk): Response
    {
        $this->guard();

        // Out-of-range chunks 404 rather than serving an empty urlset: a stale
        // index entry pointing at a chunk that no longer exists should look
        // broken to a crawler, not look like a page that legitimately has no
        // URLs.
        if ($chunk < 1 || $chunk > $this->entries->chunkCount()) {
            abort(404);
        }

        $xml = $this->cache->rememberLive(
            self::CHUNK_KEY . $this->originKey() . ':' . $chunk,
            fn (): string => $this->renderer->render($this->entries->build($chunk)),
        );

        return $this->xml($xml);
    }

    /**
     * Checked per-request, outside the cache: a 404 here must never be served
     * from (or written to) a still-warm cache entry.
     */
    private function guard(): void
    {
        if (! $this->indexing->enabled()) {
            abort(404);
        }
    }

    private function xml(string $xml): Response
    {
        return response()->make($xml, 200, ['Content-Type' => 'application/xml']);
    }

    private function originKey(): string
    {
        return hash('sha256', request()->getSchemeAndHttpHost());
    }
}
