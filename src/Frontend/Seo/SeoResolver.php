<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Block\MediaUrlResolver;
use Tbtop\Cms\Frontend\PageUrl;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

class SeoResolver
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PageUrl $url,
        private readonly MediaUrlResolver $mediaUrlResolver = new MediaUrlResolver,
    ) {}

    /**
     * @return array{title: string, description: string, canonical: string, ogImage: string|null, ogTitle: string, ogDescription: string, noindex: bool}
     */
    public function resolve(Page $page, string $locale): array
    {
        $seo = $page->seo ?? [];
        $metaTitle = $this->metaTitle($page, $seo, $locale);
        $metaDescription = $this->description($page, $seo, $locale);

        return [
            'title' => $this->title($metaTitle),
            'description' => $metaDescription,
            'canonical' => $this->canonical($page, $seo, $locale),
            'ogImage' => $this->ogImage($page, $seo),
            'ogTitle' => $this->str($seo, 'og_title') ?: $metaTitle,
            'ogDescription' => $this->str($seo, 'og_description') ?: $metaDescription,
            'noindex' => ! $page->is_indexable,
        ];
    }

    /**
     * The meta title before the global suffix is appended — also the base
     * that og:title falls back to when no og_title override is set.
     *
     * Cascade: seo.title → page.title → page.name. The last link matters:
     * name is the only required editorial field, so a page with empty
     * Title/Meta title still gets a non-empty <title> instead of a bare
     * suffix (or nothing at all when no suffix is configured).
     *
     * @param  array<string, mixed>  $seo
     */
    private function metaTitle(Page $page, array $seo, string $locale): string
    {
        return $this->str($seo, 'title')
            ?: (string) $page->getTranslation('title', $locale)
            ?: (string) $page->getTranslation('name', $locale);
    }

    private function title(string $base): string
    {
        $suffix = (string) $this->settings->get('seo.title_suffix', '');

        if ($suffix === '') {
            return $base;
        }

        return $base === '' ? $suffix : $base . ' ' . $suffix;
    }

    /** @param array<string, mixed> $seo */
    private function canonical(Page $page, array $seo, string $locale): string
    {
        $override = $this->str($seo, 'canonical');

        if ($override !== '') {
            return $override;
        }

        return url($this->url->build((string) $page->pathFor($locale), $locale));
    }

    /**
     * og_image, page.image, and seo.default_og_image are all $s->media()
     * fields — each stores a media id, not a URL, so all three go through
     * the same id-to-url resolver. Fallback order: the page's explicit
     * og_image override, then the page's own image, then the global default.
     *
     * @param  array<string, mixed>  $seo
     */
    private function ogImage(Page $page, array $seo): ?string
    {
        $override = $this->str($seo, 'og_image');

        if ($override !== '') {
            return $this->mediaUrlResolver->resolve($override);
        }

        if ($page->image !== null && $page->image !== '') {
            return $this->mediaUrlResolver->resolve($page->image);
        }

        $global = $this->settings->get('seo.default_og_image');

        return is_string($global) && $global !== '' ? $this->mediaUrlResolver->resolve($global) : null;
    }

    /** @param array<string, mixed> $seo */
    private function description(Page $page, array $seo, string $locale): string
    {
        $override = $this->str($seo, 'description');

        if ($override !== '') {
            return $override;
        }

        $summary = (string) $page->getTranslation('summary', $locale);

        if ($summary !== '') {
            return $summary;
        }

        $global = $this->settings->get('seo.default_description', '');

        return is_string($global) ? $global : '';
    }

    /** @param array<string, mixed> $seo */
    private function str(array $seo, string $key): string
    {
        $value = $seo[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
