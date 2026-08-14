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
     * is 500 pages.
     *
     * The floor of one page per chunk means a budget smaller than the locale
     * count is exceeded rather than honoured — a 1-URL budget on a two-locale
     * site still emits two URLs, because a page's locales are never split
     * across files and a chunk of zero pages would paginate forever. The
     * budget is a target for tuning build time, and overshooting it by one
     * page is the lesser fault against not serving the page at all.
     *
     * The protocol's 50 000 ceiling is not at risk from this: reaching it
     * would take a site with more than 50 000 active locales.
     */
    private function pagesPerChunk(?int $perChunk, int $localeCount): int
    {
        $budget = $perChunk === null
            ? $this->defaultPerChunk()
            : max(1, min($perChunk, self::MAX_PER_CHUNK));

        return max(1, (int) floor($budget / max(1, $localeCount)));
    }

    /**
     * The sitemap protocol's own ceiling. Clamped here rather than trusted
     * from the settings form: the form validates what an editor types, but
     * the value can also arrive from a host's config or from a row written
     * straight to the database, and a chunk past this limit is rejected
     * wholesale by the crawler that reads it.
     */
    private const int MAX_PER_CHUNK = 50000;

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
            return min((int) $stored, self::MAX_PER_CHUNK);
        }

        return max(1, min((int) config('tbtop-cms.seo.sitemap.per_chunk', 1000), self::MAX_PER_CHUNK));
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
     * One absolute URL per locale the page has a slug for — minus any locale
     * whose canonical override points somewhere other than that same URL.
     * Filtered here, before entriesForPage() builds anything from the map,
     * so an excluded locale disappears from this page's own entry AND from
     * every other locale's xhtml:link alternates in one place, rather than
     * needing a second cleanup pass over the built entries.
     *
     * A page left with no locales after filtering (every one canonicalized
     * away) yields an empty map, which entriesForPage() already treats as
     * "no entries for this page" — the same path a page with no slug in any
     * active locale takes today.
     *
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function alternates(Page $page, array $locales): array
    {
        $alternates = [];
        $seo = is_array($page->seo) ? $page->seo : [];

        foreach ($locales as $locale) {
            $path = $page->pathFor($locale);

            if ($path === null) {
                continue;
            }

            $loc = url($this->url->build($path, $locale));

            if ($this->isCanonicalizedAway($seo, $locale, $loc)) {
                continue;
            }

            $alternates[$locale] = $loc;
        }

        return $alternates;
    }

    /**
     * True when this locale names a canonical override that is NOT the
     * locale's own sitemap URL — an external domain or another internal page
     * both count, since either one tells a crawler this address is not the
     * authoritative one. A page canonicalized to itself (same URL, perhaps
     * with different trailing-slash/host-case spelling) is not excluded: that
     * is the normal, expected shape for a page with no cross-page canonical
     * concern, and Ghost-style tooling only flags a MISMATCH, not an override
     * that happens to restate the same address.
     *
     * @param  array<string, mixed>  $seo
     */
    private function isCanonicalizedAway(array $seo, string $locale, string $loc): bool
    {
        $override = $this->seo->validCanonicalOverride($seo, $locale);

        if ($override === null) {
            return false;
        }

        return self::normalizeUrl($override) !== self::normalizeUrl($loc);
    }

    /**
     * Comparable form for a self-canonical check. The ONLY tolerated
     * spelling differences are the case-insensitive scheme and host (RFC
     * 3986) and at most one trailing slash on the path; every other
     * component parse_url() reports — userinfo, port, query, fragment —
     * participates verbatim, so an override differing in any of them counts
     * as non-self. The path also stays case-sensitive: on most servers it
     * is, and lower-casing it could turn a genuinely different page into a
     * false "self" match.
     */
    private static function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? mb_strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) ? mb_strtolower($parts['host']) : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $userinfo = $user !== '' || $pass !== '' ? "{$user}{$pass}@" : '';

        // At most ONE trailing slash is cosmetic; rtrim would also collapse
        // "/foo//" into "/foo", turning an override that names a genuinely
        // different path (repeated slashes are significant on some servers)
        // into a false self match.
        if (str_ends_with($path, '/')) {
            $path = mb_substr($path, 0, -1);
        }

        return "{$scheme}://{$userinfo}{$host}{$port}{$path}{$query}{$fragment}";
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
