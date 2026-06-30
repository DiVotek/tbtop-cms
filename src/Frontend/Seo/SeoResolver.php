<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Frontend\PageUrl;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

class SeoResolver
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PageUrl $url,
    ) {}

    /**
     * @return array{title: string, description: string, canonical: string, ogImage: string|null, noindex: bool}
     */
    public function resolve(Page $page, string $locale): array
    {
        $seo = $page->seo ?? [];

        return [
            'title' => $this->title($page, $seo, $locale),
            'description' => $this->description($page, $seo, $locale),
            'canonical' => $this->canonical($page, $seo, $locale),
            'ogImage' => $this->ogImage($seo),
            'noindex' => ! $page->is_indexable,
        ];
    }

    /** @param array<string, mixed> $seo */
    private function title(Page $page, array $seo, string $locale): string
    {
        $base = $this->str($seo, 'title') ?: (string) $page->getTranslation('title', $locale, false);
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

    /** @param array<string, mixed> $seo */
    private function ogImage(array $seo): ?string
    {
        $override = $this->str($seo, 'og_image');

        if ($override !== '') {
            return $override;
        }

        $global = $this->settings->get('seo.default_og_image');

        return is_string($global) && $global !== '' ? $global : null;
    }

    /** @param array<string, mixed> $seo */
    private function description(Page $page, array $seo, string $locale): string
    {
        $override = $this->str($seo, 'description');

        if ($override !== '') {
            return $override;
        }

        $summary = (string) $page->getTranslation('summary', $locale, false);

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
