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
            'ogImage' => $this->ogImage($seo),
            'ogTitle' => $this->str($seo, 'og_title') ?: $metaTitle,
            'ogDescription' => $this->str($seo, 'og_description') ?: $metaDescription,
            'noindex' => ! $page->is_indexable,
        ];
    }

    /**
     * The meta title before the global suffix is appended — also the base
     * that og:title falls back to when no og_title override is set.
     *
     * @param  array<string, mixed>  $seo
     */
    private function metaTitle(Page $page, array $seo, string $locale): string
    {
        return $this->str($seo, 'title') ?: (string) $page->getTranslation('title', $locale);
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
     * og_image and seo.default_og_image are $s->media() fields — both store
     * a media id, not a URL, so both go through the same id-to-url resolver
     * as the page override and the global fallback.
     *
     * @param  array<string, mixed>  $seo
     */
    private function ogImage(array $seo): ?string
    {
        $override = $this->str($seo, 'og_image');

        if ($override !== '') {
            return $this->mediaUrlResolver->resolve($override);
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
