<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

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
    ) {}

    /**
     * Same rule as SitemapEntryBuilder::alternates(): one absolute URL per
     * frontend-active locale for which the page has a slug, kept consistent
     * with what the sitemap emits for hreflang.
     *
     * @return list<Alternate>
     */
    public function forPage(Page $page): array
    {
        $alternates = [];

        foreach ($this->localeCodes() as $code) {
            $path = $page->pathFor($code);

            if ($path !== null) {
                $alternates[] = ['locale' => $code, 'url' => url($this->url->build($path, $code))];
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

    /** @return list<string> */
    private function localeCodes(): array
    {
        return array_map(
            static fn (array $row): string => $row['locale'],
            $this->languages->frontendActive(),
        );
    }
}
