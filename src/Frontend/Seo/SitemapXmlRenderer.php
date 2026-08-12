<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use SimpleXMLElement;
use Tbtop\Cms\Language\Languages;

/**
 * @phpstan-import-type SitemapEntry from SitemapEntryBuilder
 */
class SitemapXmlRenderer
{
    public function __construct(private readonly Languages $languages) {}

    /** @param  list<SitemapEntry>  $entries */
    public function render(array $entries): string
    {
        $urlset = new SimpleXMLElement(
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            . 'xmlns:xhtml="http://www.w3.org/1999/xhtml" '
            . 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"/>',
        );

        foreach ($entries as $entry) {
            $this->appendUrl($urlset, $entry);
        }

        $xml = $urlset->asXML();

        return $xml === false ? '' : $xml;
    }

    /**
     * The index document: one <sitemap> per chunk. Served at sitemap.xml so
     * the address a crawler already knows keeps working — the chunks live at
     * sitemap-N.xml behind it.
     *
     * @param  list<string>  $locs
     */
    public function renderIndex(array $locs, string $lastmod): string
    {
        $index = new SimpleXMLElement(
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>',
        );

        foreach ($locs as $loc) {
            $entry = $index->addChild('sitemap');
            $entry->addChild('loc', htmlspecialchars($loc, ENT_XML1));

            if ($lastmod !== '') {
                $entry->addChild('lastmod', $lastmod);
            }
        }

        $xml = $index->asXML();

        return $xml === false ? '' : $xml;
    }

    /** @param  SitemapEntry  $entry */
    private function appendUrl(SimpleXMLElement $urlset, array $entry): void
    {
        $url = $urlset->addChild('url');
        $url->addChild('loc', htmlspecialchars($entry['loc'], ENT_XML1));

        if ($entry['lastmod'] !== '') {
            $url->addChild('lastmod', $entry['lastmod']);
        }

        $url->addChild('priority', $entry['priority']);

        $this->appendAlternates($url, $entry['alternates']);
        $this->appendImage($url, $entry['image']);
    }

    /**
     * image:loc is the only required child of image:image — caption and title
     * are optional and would repeat the page title the crawler already has
     * from the page itself.
     *
     * The URL arrives absolute from SeoResolver::ownImage() — see AbsoluteUrl
     * for why stored media URLs are root-relative to begin with.
     */
    private function appendImage(SimpleXMLElement $url, ?string $image): void
    {
        if ($image === null || $image === '') {
            return;
        }

        $namespace = 'http://www.google.com/schemas/sitemap-image/1.1';

        $node = $url->addChild('image:image', null, $namespace);
        $node->addChild('image:loc', htmlspecialchars($image, ENT_XML1), $namespace);
    }

    /** @param  array<string, string>  $alternates */
    private function appendAlternates(SimpleXMLElement $url, array $alternates): void
    {
        $xhtmlNamespace = 'http://www.w3.org/1999/xhtml';
        $default = $this->languages->defaultLocale();

        foreach ($alternates as $locale => $loc) {
            $this->appendAlternate($url, $xhtmlNamespace, $locale, $loc);
        }

        if ($default !== '' && isset($alternates[$default])) {
            $this->appendAlternate($url, $xhtmlNamespace, 'x-default', $alternates[$default]);
        }
    }

    private function appendAlternate(SimpleXMLElement $url, string $namespace, string $hreflang, string $loc): void
    {
        $link = $url->addChild('link', null, $namespace);
        $link->addAttribute('rel', 'alternate');
        $link->addAttribute('hreflang', $hreflang);
        $link->addAttribute('href', $loc);
    }
}
