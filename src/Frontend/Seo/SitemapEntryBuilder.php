<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageUrl;
use Tbtop\Cms\Settings\Settings;

/**
 * @phpstan-type SitemapEntry array{loc: string, lastmod: string, priority: string, image: string|null, alternates: array<string, string>}
 */
class SitemapEntryBuilder
{
    public function __construct(
        private readonly PageUrl $url,
        private readonly Languages $languages,
        private readonly SeoResolver $seo,
        private readonly Settings $settings,
    ) {}

    /**
     * One chunk of the sitemap, $perChunk URLs at most.
     *
     * Paged at the database rather than built whole and sliced: the whole
     * point of chunking is that a large site never loads every page into
     * memory in one request. A page yields one URL per locale it has a slug
     * for, so the row window is sized in pages and the resulting URL count
     * per chunk is a ceiling, not an exact figure — chunk boundaries land on
     * page boundaries so a page's locales never split across two files.
     *
     * @return list<SitemapEntry>
     */
    public function build(int $chunk = 1, ?int $perChunk = null): array
    {
        $locales = $this->localeCodes();

        if ($locales === []) {
            return [];
        }

        $pagesPerChunk = $this->pagesPerChunk($perChunk, count($locales));

        $pages = $this->query()
            ->orderBy('id')
            ->forPage(max(1, $chunk), $pagesPerChunk)
            ->get();

        $entries = [];

        foreach ($pages as $page) {
            array_push($entries, ...$this->entriesForPage($page, $locales));
        }

        return $entries;
    }

    /**
     * How many chunks the sitemap index has to link to. Always at least one,
     * so an empty site still serves a valid (if empty) sitemap rather than
     * an index pointing at nothing.
     */
    public function chunkCount(?int $perChunk = null): int
    {
        $locales = $this->localeCodes();

        if ($locales === []) {
            return 1;
        }

        $pages = $this->query()->count();
        $pagesPerChunk = $this->pagesPerChunk($perChunk, count($locales));

        return max(1, (int) ceil($pages / $pagesPerChunk));
    }

    /**
     * Rows per chunk, derived from the URL budget: every page contributes up
     * to one URL per active locale, so a 1000-URL budget on a two-locale site
     * is 500 pages. At least one page per chunk, or a site with more locales
     * than the budget would divide to zero and paginate forever.
     */
    private function pagesPerChunk(?int $perChunk, int $localeCount): int
    {
        $budget = $perChunk ?? $this->defaultPerChunk();

        return max(1, (int) floor($budget / max(1, $localeCount)));
    }

    /**
     * The editor's setting wins over the config default: how big a chunk can
     * get before the request building it runs long depends on the host, and
     * the person who notices is the one watching the site, not the one who
     * published the package. The config value stays as the fallback for a
     * fresh install and as the host's own override.
     */
    public function defaultPerChunk(): int
    {
        $stored = $this->settings->get('seo.sitemap_per_chunk');

        if (is_numeric($stored) && (int) $stored >= 1) {
            return (int) $stored;
        }

        return max(1, (int) config('tbtop-cms.seo.sitemap.per_chunk', 1000));
    }

    /** @return Builder<Page> */
    private function query(): Builder
    {
        return Page::query()
            ->live()
            ->where('is_indexable', true)
            ->with('slugs');
    }

    /**
     * @param  list<string>  $locales
     * @return list<SitemapEntry>
     */
    private function entriesForPage(Page $page, array $locales): array
    {
        $alternates = $this->alternates($page, $locales);

        if ($alternates === []) {
            return [];
        }

        $lastmod = $this->lastmod($page);
        $priority = $this->priority($page);
        $image = $this->image($page);

        return (new Collection($locales))
            ->filter(static fn (string $locale): bool => isset($alternates[$locale]))
            ->map(static fn (string $locale): array => [
                'loc' => $alternates[$locale],
                'lastmod' => $lastmod,
                'priority' => $priority,
                'image' => $image,
                'alternates' => $alternates,
            ])
            ->values()
            ->all();
    }

    /**
     * Depth-derived, not configurable: Google has ignored <priority> since
     * 2015 and the engines that still read it treat it as a hint about
     * relative importance within one site. Depth is exactly that hint and
     * needs no editor input — the home page outranks a section, which
     * outranks a leaf.
     *
     * Formatted to one decimal because the spec's range is 0.0–1.0 and a
     * locale with a comma decimal separator would otherwise emit "0,8".
     */
    private function priority(Page $page): string
    {
        $depth = substr_count(trim((string) $page->pathFor($this->languages->defaultLocale()), '/'), '/');
        $isHome = (string) $page->pathFor($this->languages->defaultLocale()) === '';

        $priority = $isHome ? 1.0 : max(0.3, 0.8 - ($depth * 0.2));

        return number_format($priority, 1, '.', '');
    }

    /**
     * The page's own image only — see SeoResolver::ownImage(). Listing the
     * site-wide default against every URL would claim one image belongs to
     * the whole site.
     */
    private function image(Page $page): ?string
    {
        return $this->seo->ownImage($page, is_array($page->seo) ? $page->seo : []);
    }

    private function lastmod(Page $page): string
    {
        $updatedAt = $page->getAttribute('updated_at');

        return $updatedAt instanceof Carbon ? $updatedAt->toAtomString() : '';
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function alternates(Page $page, array $locales): array
    {
        $alternates = [];

        foreach ($locales as $locale) {
            $path = $page->pathFor($locale);

            if ($path !== null) {
                $alternates[$locale] = url($this->url->build($path, $locale));
            }
        }

        return $alternates;
    }

    /** @return list<string> */
    private function localeCodes(): array
    {
        return array_map(
            static fn (array $row): string => $row['locale'],
            $this->languages->frontendActive(),
        );
    }
}
