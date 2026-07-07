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
            . 'xmlns:xhtml="http://www.w3.org/1999/xhtml"/>',
        );

        foreach ($entries as $entry) {
            $this->appendUrl($urlset, $entry);
        }

        $xml = $urlset->asXML();

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

        $this->appendAlternates($url, $entry['alternates']);
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
