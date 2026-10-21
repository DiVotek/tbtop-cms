<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Frontend\Seo\Schema\SchemaContextFactory;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageUrl;

/**
 * The hreflang side of a page's SEO: one absolute URL per locale the page
 * exists in, the x-default among them, and the og:locale:alternate values
 * derived from the same list.
 *
 * @phpstan-import-type Alternate from SeoResolver
 */
final class SeoAlternates
{
    public function __construct(
        private readonly PageUrl $url,
        private readonly Languages $languages,
        private readonly SchemaContextFactory $factory,
    ) {}

    /**
     * One absolute URL per frontend-active locale (or per $locales) for which
     * the page has a slug, minus any locale whose canonical override points
     * elsewhere: a URL that names another address as authoritative is not an
     * hreflang alternate. The sitemap reads the same list
     * (SitemapEntryBuilder::alternates()), so head and sitemap agree.
     *
     * @param  list<string>|null  $locales
     * @return list<Alternate>
     */
    public function forPage(Page $page, ?array $locales = null): array
    {
        $alternates = [];
        $seo = is_array($page->seo) ? $page->seo : [];

        foreach ($locales ?? $this->localeCodes() as $code) {
            $path = $page->pathFor($code);

            if ($path === null) {
                continue;
            }

            $url = url($this->url->build($path, $code));

            if (! $this->isCanonicalizedAway($seo, $code, $url)) {
                $alternates[] = ['locale' => $code, 'url' => $url];
            }
        }

        return $alternates;
    }

    /** @param  list<Alternate>  $alternates */
    public function xDefault(array $alternates): ?string
    {
        $default = $this->languages->defaultLocale();

        foreach ($alternates as $alternate) {
            if ($alternate['locale'] === $default) {
                return $alternate['url'];
            }
        }

        return null;
    }

    /**
     * Derived from the hreflang alternates (locales the page actually exists
     * in), not from every frontend-active locale: og:locale:alternate is a
     * claim that this content is available in that locale. A language with
     * no og_locale is left out rather than published as a bare `en`.
     *
     * @param  list<Alternate>  $alternates
     * @return list<string>
     */
    public function ogLocales(string $locale, array $alternates): array
    {
        $ogLocales = [];

        foreach (array_column($alternates, 'locale') as $code) {
            $ogLocale = $code !== $locale ? $this->languages->ogLocale($code) : null;

            if ($ogLocale !== null) {
                $ogLocales[] = $ogLocale;
            }
        }

        return $ogLocales;
    }

    /**
     * True when this locale names a canonical override that is NOT the
     * locale's own URL — an external domain or another internal page
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
        $override = $this->factory->validCanonicalOverride($seo, $locale);

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
