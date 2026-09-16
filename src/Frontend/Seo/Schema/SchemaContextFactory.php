<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

use Tbtop\Cms\Block\MediaUrlResolver;
use Tbtop\Cms\Frontend\Seo\AbsoluteUrl;
use Tbtop\Cms\Frontend\Seo\LocalizedSeo;
use Tbtop\Cms\Frontend\Seo\PageOwnSeo;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\PageUrl;
use Tbtop\Cms\Settings\Settings;

/**
 * Builds the SchemaContext a page's schema.org node(s) — page-type node and
 * block-derived nodes alike — are built from, sharing the canonical/
 * og-image/name/description/author cascades SeoResolver's rendered head
 * tags use rather than deriving them twice.
 *
 * Constructible with the same purpose-built Settings a caller passes
 * SeoResolver directly — see that class's constructor comment.
 */
final class SchemaContextFactory
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PageUrl $url,
        private readonly PageTypeRegistry $pageTypes,
        private readonly MediaUrlResolver $mediaUrlResolver = new MediaUrlResolver,
    ) {}

    /**
     * $source is the page type's source() result; pass it when the caller
     * already paid for that call. Left null, a source-backed type's source()
     * is fetched here — so a caller holding a legitimately null source for a
     * source-backed page pays that fetch twice (PageController never does:
     * it 404s on a null source before reaching here). Content-only types
     * are never asked.
     */
    public function make(Page $page, string $locale, ?object $source = null): SchemaContext
    {
        $seo = is_array($page->seo) ? $page->seo : [];
        $ogImageDetails = $this->ogImageDetails($page, $seo);
        $metaTitle = PageOwnSeo::title($page, $seo, $locale);
        $ogTitle = LocalizedSeo::value($seo['og_title'] ?? null, $locale) ?: $metaTitle;
        $ogDescription = LocalizedSeo::value($seo['og_description'] ?? null, $locale)
            ?: $this->description($page, $seo, $locale);

        // The UNtemplated title/description: a schema `name` should be the
        // thing's name, not the browser-tab string with the site name
        // appended.
        $currentLabel = (string) $page->getTranslation('title', $locale, false)
            ?: (string) $page->getTranslation('name', $locale, false);

        if ($source === null && $this->pageTypes->isSourceBacked($page->type)) {
            $source = $this->pageTypes->get($page->type)->source($page);
        }

        return new SchemaContext(
            page: $page,
            locale: $locale,
            source: $source,
            name: $currentLabel !== '' ? $currentLabel : $ogTitle,
            description: $ogDescription,
            url: $this->canonical($page, $seo, $locale),
            image: $ogImageDetails['url'] ?? null,
            author: $this->identityAuthor(),
            imageAlt: $ogImageDetails['alt'] ?? null,
            imageMime: $ogImageDetails['mime'] ?? null,
        );
    }

    /** @param array<string, mixed> $seo */
    private function canonical(Page $page, array $seo, string $locale): string
    {
        $override = $this->validCanonicalOverride($seo, $locale);

        if ($override !== null) {
            return $override;
        }

        return url($this->url->build((string) $page->pathFor($locale), $locale));
    }

    /**
     * Re-validated here rather than trusted from the stored value: a row can
     * carry a value written before the form rule existed. A scheme other
     * than http/https is worse than no override, so it is treated as absent.
     *
     * @param  array<string, mixed>  $seo
     */
    public function validCanonicalOverride(array $seo, string $locale): ?string
    {
        $override = LocalizedSeo::value($seo['canonical'] ?? null, $locale);

        if ($override === '') {
            return null;
        }

        // Scheme alone is not enough: parse_url('http:foo') yields a scheme
        // but no host, and such a value is not an absolute URL a crawler can
        // follow — require a non-empty host too before trusting the override.
        $parts = parse_url($override);

        if ($parts === false) {
            return null;
        }

        $scheme = mb_strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        return in_array($scheme, ['http', 'https'], true) && $host !== ''
            ? $override
            : null;
    }

    /**
     * Fallback order: the page's explicit og_image override, then the
     * page's own image, then the global default. Returns alt/mime alongside
     * the URL since og:image:alt/og:image:type must follow whichever image
     * actually resolved.
     *
     * @param  array<string, mixed>  $seo
     * @return array{url: string, alt: string|null, mime: string|null}|null
     */
    private function ogImageDetails(Page $page, array $seo): ?array
    {
        // A page that named an image has made its choice, and a broken id is
        // still that choice — falling through to the site default here would
        // quietly publish a different image than the one the editor picked,
        // which is worse than publishing none.
        if (PageOwnSeo::namesImage($page, $seo)) {
            return $this->ownImageDetails($page, $seo);
        }

        $global = $this->settings->get('seo.default_og_image');

        return is_string($global) && $global !== '' ? $this->imageDetails($global) : null;
    }

    /**
     * The image this page carries itself, with no site-wide fallback —
     * listing the global default against every sitemap URL would claim one
     * image belongs to the whole site.
     *
     * @param  array<string, mixed>  $seo
     */
    public function ownImage(Page $page, array $seo): ?string
    {
        return $this->ownImageDetails($page, $seo)['url'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return array{url: string, alt: string|null, mime: string|null}|null
     */
    private function ownImageDetails(Page $page, array $seo): ?array
    {
        $override = $seo['og_image'] ?? null;

        if (is_string($override) && $override !== '') {
            return $this->imageDetails($override);
        }

        if ($page->image !== null && $page->image !== '') {
            return $this->imageDetails($page->image);
        }

        return null;
    }

    /**
     * Mime is dropped unless it starts with `image/` — a non-image mime
     * published as og:image:type is worse than omitting the tag entirely.
     *
     * @return array{url: string, alt: string|null, mime: string|null}|null
     */
    private function imageDetails(string $id): ?array
    {
        $details = $this->mediaUrlResolver->details($id);

        if ($details === null) {
            return null;
        }

        $url = AbsoluteUrl::make($details['url']);

        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'alt' => $details['alt'] !== null && $details['alt'] !== '' ? $details['alt'] : null,
            'mime' => str_starts_with($details['mime'], 'image/') ? $details['mime'] : null,
        ];
    }

    /**
     * The description cascade og:description and the schema context's own
     * description fall back to: seo.description → page summary →
     * site-wide default.
     *
     * @param  array<string, mixed>  $seo
     */
    public function description(Page $page, array $seo, string $locale): string
    {
        $override = LocalizedSeo::value($seo['description'] ?? null, $locale);

        if ($override !== '') {
            return $override;
        }

        $summary = (string) $page->getTranslation('summary', $locale);

        if ($summary !== '') {
            return $summary;
        }

        return LocalizedSeo::value($this->settings->get('seo.default_description', ''), $locale);
    }

    /**
     * Comes from site identity settings, not per-page data — reuses
     * identityName() so the identity node and the Article author name the
     * same entity.
     *
     * @return array{'@type': string, name: string}|null
     */
    private function identityAuthor(): ?array
    {
        $type = (string) $this->settings->get('seo.identity.type', 'none');
        $name = $this->identityName();

        if ($name === '') {
            return null;
        }

        return match ($type) {
            'organization' => ['@type' => 'Organization', 'name' => $name],
            'person' => ['@type' => 'Person', 'name' => $name],
            default => null,
        };
    }

    /** Shared with SeoResolver::identityName() so the identity node and the Article author name the same entity. */
    public function identityName(): string
    {
        $name = (string) $this->settings->get('seo.identity.name', '');

        return $name !== '' ? $name : (string) $this->settings->get('site.name', '');
    }
}
