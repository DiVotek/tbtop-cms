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
 */
class SeoResolver
{
    private readonly IndexingSetting $indexing;

    private readonly PageSchema $pageSchema;

    private readonly SeoTemplates $templates;

    public function __construct(
        private readonly Settings $settings,
        private readonly PageUrl $url,
        private readonly Languages $languages,
        private readonly JsonLd $jsonLd,
        private readonly MediaUrlResolver $mediaUrlResolver = new MediaUrlResolver,
        ?IndexingSetting $indexing = null,
        ?PageSchema $pageSchema = null,
        ?SeoTemplates $templates = null,
    ) {
        // Built from the same Settings instance rather than resolved from the
        // container: this resolver is constructed directly in places that pass
        // a purpose-built Settings, and a container-resolved reader there would
        // consult a different one.
        $this->indexing = $indexing ?? new IndexingSetting($settings);
        // Same reasoning, one field over: built from the PageUrl this resolver
        // was handed so hasPart URLs are prefixed by the same rule canonical
        // and alternates use.
        $this->pageSchema = $pageSchema ?? new PageSchema($url);
        // Same Settings instance again: a container-resolved reader would
        // consult a different one wherever this resolver is built by hand.
        $this->templates = $templates ?? new SeoTemplates($settings);
    }

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
            'title' => $this->title($page, $metaTitle, $locale, $breadcrumbs),
            'description' => $this->descriptionWithTemplate($page, $metaDescription, $locale, $breadcrumbs),
            'canonical' => $canonical,
            'ogImage' => $ogImage,
            'ogTitle' => $ogTitle,
            'ogDescription' => $ogDescription,
            'noindex' => ! $page->is_indexable || ! $this->indexing->enabled(),
            'siteName' => $siteName,
            'ogType' => 'website',
            'ogUrl' => $canonical,
            'ogLocale' => $locale,
            'ogLocaleAlternates' => $this->ogLocaleAlternates($locale, $alternates),
            'twitterCard' => $this->twitterCard($ogImage),
            'alternates' => $alternates,
            'xDefault' => $this->xDefault($alternates),
            'favicon' => $this->favicon(),
            'jsonLd' => $this->jsonLd(
                $page,
                $locale,
                $siteName,
                $ogTitle,
                $ogDescription,
                $canonical,
                $ogImage,
                $breadcrumbs,
            ),
        ];
    }

    private function twitterCard(?string $ogImage): string
    {
        $choice = (string) $this->settings->get('seo.twitter_card', 'auto');

        return match ($choice) {
            'summary', 'summary_large_image' => $choice,
            default => $ogImage !== null ? 'summary_large_image' : 'summary',
        };
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
    private function jsonLd(
        Page $page,
        string $locale,
        string $siteName,
        string $ogTitle,
        string $ogDescription,
        string $canonical,
        ?string $ogImage,
        array $breadcrumbs,
    ): array {
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

        // The page's own object uses the UNtemplated title/description (the
        // same base og: falls back to): a schema `name` should be the thing's
        // name, not the browser-tab string with the site name appended.
        $pageSchema = $this->pageSchema->build($page, $locale, [
            'name' => $currentLabel !== '' ? $currentLabel : $ogTitle,
            'description' => $ogDescription,
            'url' => $canonical,
            'image' => $ogImage,
            'profileImage' => $this->profileImage($page),
        ]);

        if ($pageSchema !== null) {
            $objects[] = $pageSchema;
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
        $logo = AbsoluteUrl::make(
            is_string($logoId) && $logoId !== '' ? $this->mediaUrlResolver->resolve($logoId) : null,
        );

        return $this->jsonLd->identity($type, $name, url('/'), $logo, $this->sameAs());
    }

    /**
     * Validated again at emit time (not only on save in SeoSettingsPage):
     * the tags field only enforces "array", so a programmatically-written
     * setting could hold anything — non-URL entries must never be published
     * as sameAs structured data.
     *
     * @return list<string>
     */
    private function sameAs(): array
    {
        $raw = $this->settings->get('seo.identity.same_as', []);

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            static fn (mixed $url): bool => is_string($url) && ProfileUrl::valid($url),
        ));
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
     * The template comes from SeoTemplates: the page type's own template if
     * one is set for this locale, otherwise the site-wide `seo.title_template`
     * (translatable). Either one supersedes the legacy single-space suffix —
     * existing sites that never touch these settings must see byte-identical
     * titles, so an empty template falls straight back to append-suffix.
     *
     * @param  list<Breadcrumb>  $breadcrumbs
     */
    private function title(Page $page, string $base, string $locale, array $breadcrumbs): string
    {
        $template = $this->templates->title($page->type, $locale);

        if ($template === '') {
            return $this->titleWithSuffix($base);
        }

        return $this->renderTemplate($template, ['{title}' => $base], $locale, $page, $breadcrumbs);
    }

    /**
     * Mirrors the title template, per-type fallback included: applied on top
     * of whatever the description cascade resolved to, with an empty template
     * meaning "use the description as is" — existing sites keep
     * byte-identical descriptions until they opt in. Shares
     * `seo.title_separator` for {sep}.
     *
     * @param  list<Breadcrumb>  $breadcrumbs
     */
    private function descriptionWithTemplate(Page $page, string $base, string $locale, array $breadcrumbs): string
    {
        $template = $this->templates->description($page->type, $locale);

        if ($template === '') {
            return $base;
        }

        return $this->renderTemplate($template, ['{description}' => $base], $locale, $page, $breadcrumbs);
    }

    private function titleWithSuffix(string $base): string
    {
        $suffix = (string) $this->settings->get('seo.title_suffix', '');

        if ($suffix === '') {
            return $base;
        }

        return $base === '' ? $suffix : $base . ' ' . $suffix;
    }

    /**
     * {parent} and {date} exist for the per-type templates: an article title
     * reads better as "Post — Blog — Site" than as the site-wide shape, and
     * only a template that knows it is rendering an article can say so.
     *
     * Both resolve to an empty string when the page has no parent or no
     * publication date — cleanupRendered() then removes the separator that
     * would have been left stranded, so one template serves both a nested
     * page and a top-level one.
     *
     * @param  array<string, string>  $vars  Template-specific substitutions ({title} or {description}).
     * @param  list<Breadcrumb>  $breadcrumbs
     */
    private function renderTemplate(string $template, array $vars, string $locale, Page $page, array $breadcrumbs): string
    {
        $siteName = (string) $this->settings->get('site.name', '');
        $separator = LocalizedSeo::value($this->settings->get('seo.title_separator', '—'), $locale) ?: '—';

        $rendered = strtr($template, $vars + [
            '{site_name}' => $siteName,
            '{sep}' => $separator,
            '{parent}' => self::parentLabel($breadcrumbs),
            '{date}' => self::publishedDate($page, $locale),
        ]);

        return $this->cleanupRendered($rendered, $separator);
    }

    /**
     * The direct parent's label — the last ancestor in the trail, which the
     * caller already built for the breadcrumbs prop, so this costs no query.
     *
     * @param  list<Breadcrumb>  $breadcrumbs
     */
    private static function parentLabel(array $breadcrumbs): string
    {
        if ($breadcrumbs === []) {
            return '';
        }

        return $breadcrumbs[count($breadcrumbs) - 1]['label'];
    }

    /**
     * Gated on isLive() rather than on published_at alone, same rule
     * PageSchema::dates() applies: a draft or expired page reached through
     * admin preview must not stamp a publication date into its title.
     *
     * Formatted through ->locale($locale) rather than Carbon's ambient locale.
     * On the frontend the two agree, but SerpPreview resolves every locale
     * inside ONE request with a single app locale — leaving it ambient made
     * the Ukrainian preview card carry an English date. The locale being
     * resolved is the only one that describes the string being built.
     */
    private static function publishedDate(Page $page, string $locale): string
    {
        return $page->published_at !== null && $page->isLive()
            ? $page->published_at->locale($locale)->isoFormat('LL')
            : '';
    }

    /**
     * An empty substitution leaves a dangling separator and/or a run of
     * whitespace behind (e.g. "{title} {sep} {site_name}" with an empty
     * site_name renders "My Page — ").
     *
     * Three passes: collapse whitespace runs, collapse a run of separators
     * left adjacent in the MIDDLE down to one, then strip any separator
     * stranded at either edge. The middle pass is what lets {parent} and
     * {date} sit between two other placeholders — a top-level page rendering
     * "{title} {sep} {parent} {sep} {site_name}" would otherwise read
     * "About — — Acme", which is worse than either edge case.
     */
    private function cleanupRendered(string $rendered, string $separator): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $rendered));
        $quotedSep = preg_quote($separator, '/');
        $deduped = preg_replace("/{$quotedSep}(?:\\s*{$quotedSep})+/u", $separator, $collapsed);
        $trimmed = preg_replace("/^(?:{$quotedSep}\\s*)+|(?:\\s*{$quotedSep})+\$/u", '', (string) $deduped);

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
        // A page that named an image has made its choice, and a broken id is
        // still that choice — falling through to the site default here would
        // quietly publish a different image than the one the editor picked,
        // which is worse than publishing none.
        if (self::namesAnImage($page, $seo)) {
            return $this->ownImage($page, $seo);
        }

        $global = $this->settings->get('seo.default_og_image');

        return AbsoluteUrl::make(
            is_string($global) && $global !== '' ? $this->mediaUrlResolver->resolve($global) : null,
        );
    }

    /**
     * The photo of a profile page's subject, when one was picked. Absolute
     * for the same reason og:image is: structured data is read with no page
     * context to resolve a root-relative path against (see AbsoluteUrl).
     *
     * null means "no profile photo was chosen" — PageSchema then falls back
     * to the page's own image.
     */
    private function profileImage(Page $page): ?string
    {
        $seo = is_array($page->seo) ? $page->seo : [];
        $schema = is_array($seo['schema'] ?? null) ? $seo['schema'] : [];
        $id = $schema['profile_image'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        return AbsoluteUrl::make($this->mediaUrlResolver->resolve($id));
    }

    /** @param  array<string, mixed>  $seo */
    private static function namesAnImage(Page $page, array $seo): bool
    {
        $override = $seo['og_image'] ?? null;

        return (is_string($override) && $override !== '')
            || ($page->image !== null && $page->image !== '');
    }

    /**
     * The image this page carries itself, with no site-wide fallback.
     *
     * Public because the sitemap needs exactly this and not ogImage(): the
     * global default exists so a share card is never blank, but listing it
     * against every URL in the sitemap would tell a crawler the same image
     * belongs to the whole site. A page with no image of its own is better
     * listed with none.
     *
     * @param  array<string, mixed>  $seo
     */
    public function ownImage(Page $page, array $seo): ?string
    {
        $override = $seo['og_image'] ?? null;

        if (is_string($override) && $override !== '') {
            return AbsoluteUrl::make($this->mediaUrlResolver->resolve($override));
        }

        if ($page->image !== null && $page->image !== '') {
            return AbsoluteUrl::make($this->mediaUrlResolver->resolve($page->image));
        }

        return null;
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
