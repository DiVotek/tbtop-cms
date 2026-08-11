<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Block\MediaUrlResolver;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageUrl;
use Tbtop\Cms\Settings\Settings;

/**
 * @phpstan-type Alternate array{locale: string, url: string}
 * @phpstan-type Breadcrumb array{label: string, path: string, url: string}
 * @phpstan-type Verification array{key: string, name: string, content: string}
 */
class SeoResolver
{
    /** key => the meta tag's `name` attribute. */
    private const array VERIFICATION_META_NAMES = [
        'google' => 'google-site-verification',
        'bing' => 'msvalidate.01',
        'yandex' => 'yandex-verification',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly PageUrl $url,
        private readonly Languages $languages,
        private readonly JsonLd $jsonLd,
        private readonly MediaUrlResolver $mediaUrlResolver = new MediaUrlResolver,
    ) {}

    /**
     * @param  list<Breadcrumb>  $breadcrumbs  Ancestors only (Breadcrumbs::forPage() shape), reused
     *                                         as-is from the caller's own `breadcrumbs` prop build so
     *                                         this stays a single query per request.
     * @return array{
     *     title: string, description: string, canonical: string, ogImage: string|null,
     *     ogTitle: string, ogDescription: string, noindex: bool, siteName: string,
     *     ogType: string, ogUrl: string, ogLocale: string, ogLocaleAlternates: list<string>,
     *     twitterCard: string, alternates: list<Alternate>, xDefault: string|null,
     *     favicon: string|null, jsonLd: list<array<string, mixed>>,
     *     verification: list<Verification>,
     * }
     */
    public function resolve(Page $page, string $locale, array $breadcrumbs = []): array
    {
        $seo = $page->seo ?? [];
        $metaTitle = $this->metaTitle($page, $seo, $locale);
        $metaDescription = $this->description($page, $seo, $locale);
        $canonical = $this->canonical($page, $seo, $locale);
        $ogImage = $this->ogImage($page, $seo);
        $ogTitle = LocalizedSeo::value($seo['og_title'] ?? null, $locale) ?: $metaTitle;
        // og:description falls back to the UNtemplated base, same as og:title
        // falls back to the untemplated metaTitle — social snippets should not
        // carry the site-name branding a template typically appends.
        $ogDescription = LocalizedSeo::value($seo['og_description'] ?? null, $locale) ?: $metaDescription;
        $siteName = (string) $this->settings->get('site.name', '');
        $alternates = $this->alternates($page);

        return [
            'title' => $this->title($metaTitle, $locale),
            'description' => $this->descriptionWithTemplate($metaDescription, $locale),
            'canonical' => $canonical,
            'ogImage' => $ogImage,
            'ogTitle' => $ogTitle,
            'ogDescription' => $ogDescription,
            'noindex' => ! $page->is_indexable || ! $this->indexingEnabled(),
            'siteName' => $siteName,
            'ogType' => 'website',
            'ogUrl' => $canonical,
            'ogLocale' => $locale,
            'ogLocaleAlternates' => $this->ogLocaleAlternates($locale, $alternates),
            'twitterCard' => $this->twitterCard($ogImage),
            'alternates' => $alternates,
            'xDefault' => $this->xDefault($alternates),
            'favicon' => $this->favicon(),
            'jsonLd' => $this->jsonLd($page, $locale, $siteName, $ogTitle, $canonical, $breadcrumbs),
            'verification' => $this->verification(),
        ];
    }

    /**
     * `seo.indexing_enabled` stores a plain boolean, but a setting row can
     * exist with a literally-stored JSON null (Settings::get() only applies
     * its $default when the KEY is absent, not when it decodes to null) — an
     * unset boolean must read as "indexing on", not silently flip every page
     * to noindex, so null is treated the same as a missing key here.
     */
    private function indexingEnabled(): bool
    {
        $value = $this->settings->get('seo.indexing_enabled', true);

        return $value === null || (bool) $value;
    }

    private function twitterCard(?string $ogImage): string
    {
        $choice = (string) $this->settings->get('seo.twitter_card', 'auto');

        return match ($choice) {
            'summary', 'summary_large_image' => $choice,
            default => $ogImage !== null ? 'summary_large_image' : 'summary',
        };
    }

    /** @return list<Verification> */
    private function verification(): array
    {
        $entries = [];

        foreach (self::VERIFICATION_META_NAMES as $key => $metaName) {
            $content = $this->verificationContent($key);

            if ($content !== '') {
                $entries[] = ['key' => $key, 'name' => $metaName, 'content' => $content];
            }
        }

        return $entries;
    }

    private function verificationContent(string $key): string
    {
        $raw = $this->settings->get("seo.verification.{$key}", '');

        return is_string($raw) ? VerificationCode::extract($raw) : '';
    }

    /**
     * Derived from the hreflang alternates (locales the page actually exists
     * in), not from every frontend-active locale: og:locale:alternate is a
     * claim that this content is available in that locale.
     *
     * @param  list<Alternate>  $alternates
     * @return list<string>
     */
    private function ogLocaleAlternates(string $locale, array $alternates): array
    {
        return array_values(array_filter(
            array_column($alternates, 'locale'),
            static fn (string $code): bool => $code !== $locale,
        ));
    }

    /**
     * Same rule as SitemapEntryBuilder::alternates(): one absolute URL per
     * frontend-active locale for which the page has a slug, kept consistent
     * with what the sitemap emits for hreflang.
     *
     * @return list<Alternate>
     */
    private function alternates(Page $page): array
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
    private function xDefault(array $alternates): ?string
    {
        $default = $this->languages->defaultLocale();

        foreach ($alternates as $alternate) {
            if ($alternate['locale'] === $default) {
                return $alternate['url'];
            }
        }

        return null;
    }

    private function favicon(): ?string
    {
        $id = $this->settings->get('site.favicon');

        return is_string($id) && $id !== '' ? $this->mediaUrlResolver->resolve($id) : null;
    }

    /**
     * @param  list<Breadcrumb>  $breadcrumbs
     * @return list<array<string, mixed>>
     */
    private function jsonLd(Page $page, string $locale, string $siteName, string $ogTitle, string $canonical, array $breadcrumbs): array
    {
        $objects = [];

        $website = $this->jsonLd->website($siteName);

        if ($website !== null) {
            $objects[] = $website;
        }

        // Same title→name cascade Breadcrumbs::label() uses for ancestors,
        // so the current page's own item is labeled consistently with the
        // rest of the trail rather than falling back to the (possibly
        // suffixed-elsewhere) og title.
        $currentLabel = (string) $page->getTranslation('title', $locale, false)
            ?: (string) $page->getTranslation('name', $locale, false);
        $breadcrumbList = $this->jsonLd->breadcrumbList(
            $breadcrumbs,
            $currentLabel !== '' ? $currentLabel : $ogTitle,
            $canonical,
        );

        if ($breadcrumbList !== null) {
            $objects[] = $breadcrumbList;
        }

        $identity = $this->identity();

        if ($identity !== null) {
            $objects[] = $identity;
        }

        return $objects;
    }

    /** @return array<string, mixed>|null */
    private function identity(): ?array
    {
        $type = (string) $this->settings->get('seo.identity.type', 'none');
        $name = (string) $this->settings->get('seo.identity.name', '');

        if ($name === '') {
            $name = (string) $this->settings->get('site.name', '');
        }

        $logoId = $this->settings->get('seo.identity.logo');
        $logo = is_string($logoId) && $logoId !== '' ? $this->mediaUrlResolver->resolve($logoId) : null;

        return $this->jsonLd->identity($type, $name, url('/'), $logo, $this->sameAs());
    }

    /** @return list<string> */
    private function sameAs(): array
    {
        $raw = $this->settings->get('seo.identity.same_as', []);

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn (mixed $url): bool => is_string($url) && $url !== ''));
    }

    /** @return list<string> */
    private function localeCodes(): array
    {
        return array_map(
            static fn (array $row): string => $row['locale'],
            $this->languages->frontendActive(),
        );
    }

    /**
     * The meta title before the template/suffix is applied — also the base
     * that og:title falls back to when no og_title override is set.
     *
     * Cascade: seo.title → page.title → page.name. The last link matters:
     * name is the only required editorial field, so a page with empty
     * Title/Meta title still gets a non-empty <title> instead of a bare
     * template/suffix (or nothing at all when neither is configured).
     *
     * @param  array<string, mixed>  $seo
     */
    private function metaTitle(Page $page, array $seo, string $locale): string
    {
        return LocalizedSeo::value($seo['title'] ?? null, $locale)
            ?: (string) $page->getTranslation('title', $locale)
            ?: (string) $page->getTranslation('name', $locale);
    }

    /**
     * `seo.title_template` (translatable) supersedes the legacy single-space
     * suffix when set for this locale — existing sites that never touch the
     * new setting must see byte-identical titles, so an empty template falls
     * straight back to append-suffix.
     */
    private function title(string $base, string $locale): string
    {
        $template = LocalizedSeo::value($this->settings->get('seo.title_template'), $locale);

        if ($template === '') {
            return $this->titleWithSuffix($base);
        }

        return $this->renderTemplate($template, ['{title}' => $base], $locale);
    }

    /**
     * `seo.description_template` (translatable) mirrors the title template:
     * applied on top of whatever the description cascade resolved to, with an
     * empty template meaning "use the description as is" — existing sites
     * keep byte-identical descriptions until they opt in. Shares
     * `seo.title_separator` for {sep}.
     */
    private function descriptionWithTemplate(string $base, string $locale): string
    {
        $template = LocalizedSeo::value($this->settings->get('seo.description_template'), $locale);

        if ($template === '') {
            return $base;
        }

        return $this->renderTemplate($template, ['{description}' => $base], $locale);
    }

    private function titleWithSuffix(string $base): string
    {
        $suffix = (string) $this->settings->get('seo.title_suffix', '');

        if ($suffix === '') {
            return $base;
        }

        return $base === '' ? $suffix : $base . ' ' . $suffix;
    }

    /** @param  array<string, string>  $vars  Template-specific substitutions ({title} or {description}). */
    private function renderTemplate(string $template, array $vars, string $locale): string
    {
        $siteName = (string) $this->settings->get('site.name', '');
        $separator = LocalizedSeo::value($this->settings->get('seo.title_separator', '—'), $locale) ?: '—';

        $rendered = strtr($template, $vars + [
            '{site_name}' => $siteName,
            '{sep}' => $separator,
        ]);

        return $this->cleanupRendered($rendered, $separator);
    }

    /**
     * An empty {title}/{description}/{site_name} substitution leaves a
     * dangling separator and/or run of whitespace behind (e.g.
     * "{title} {sep} {site_name}" with an empty site_name renders
     * "My Page — "). Collapses whitespace runs first, then strips any
     * separator left stranded at either edge, so the result never opens or
     * closes on a bare separator.
     */
    private function cleanupRendered(string $rendered, string $separator): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $rendered));
        $quotedSep = preg_quote($separator, '/');
        $trimmed = preg_replace("/^(?:{$quotedSep}\\s*)+|(?:\\s*{$quotedSep})+\$/u", '', $collapsed);

        return trim((string) $trimmed);
    }

    /** @param array<string, mixed> $seo */
    private function canonical(Page $page, array $seo, string $locale): string
    {
        $override = LocalizedSeo::value($seo['canonical'] ?? null, $locale);

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
     * og_image itself is NOT translatable (see PageForm::seoFields()) — an
     * image isn't per-locale, so it's read as a plain scalar.
     *
     * @param  array<string, mixed>  $seo
     */
    private function ogImage(Page $page, array $seo): ?string
    {
        $override = $seo['og_image'] ?? null;

        if (is_string($override) && $override !== '') {
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
}
