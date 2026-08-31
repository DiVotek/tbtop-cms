<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Closure;
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
     * `ownTitle`/`ownDescription` carry what the PAGE itself supplies, before
     * any site-wide filler — no template, no suffix, no default description.
     * They answer "is this filled in?", which is a question about the page: a
     * template appending the site name makes every rendered title non-empty,
     * so asking it of the rendered string answers for the site instead (see
     * SeoChecklist::titleCheck()).
     *
     * They are NOT the right input for every check — "does the title carry
     * the keyword?" is about what a crawler reads, so that one stays on the
     * rendered value (see SeoChecklist::focusKeywordChecks()). Rendering
     * itself never uses these two.
     *
     * @param  list<Breadcrumb>  $breadcrumbs  Ancestors only (Breadcrumbs::forPage() shape), reused
     *                                         as-is from the caller's own `breadcrumbs` prop build so
     *                                         this stays a single query per request.
     * @return array{
     *     title: string, description: string, canonical: string, ogImage: string|null,
     *     ogImageAlt: string|null, ogImageType: string|null,
     *     ogTitle: string, ogDescription: string, noindex: bool, siteName: string,
     *     ogType: string, ogUrl: string, ogLocale: string, ogLocaleAlternates: list<string>,
     *     articlePublishedTime: string|null, articleModifiedTime: string|null, articleAuthor: string|null,
     *     twitterCard: string, alternates: list<Alternate>, xDefault: string|null,
     *     favicon: string|null, jsonLd: list<array<string, mixed>>,
     *     ownTitle: string, ownDescription: string,
     *     verificationGoogle: string|null, verificationBing: string|null,
     * }
     */
    public function resolve(Page $page, string $locale, array $breadcrumbs = []): array
    {
        $seo = $page->seo ?? [];
        $metaTitle = $this->metaTitle($page, $seo, $locale);
        $metaDescription = $this->description($page, $seo, $locale);
        $canonical = $this->canonical($page, $seo, $locale);
        $image = $this->ogImageDetails($page, $seo);
        $ogImage = $image['url'] ?? null;
        $ogTitle = LocalizedSeo::value($seo['og_title'] ?? null, $locale) ?: $metaTitle;
        // og:description falls back to the UNtemplated base, same as og:title
        // falls back to the untemplated metaTitle — social snippets should not
        // carry the site-name branding a template typically appends.
        $ogDescription = LocalizedSeo::value($seo['og_description'] ?? null, $locale) ?: $metaDescription;
        $siteName = (string) $this->settings->get('site.name', '');
        $alternates = $this->alternates($page);
        $isArticle = $page->type === 'article';

        return [
            'title' => $this->title($page, $metaTitle, $locale, $breadcrumbs),
            'description' => $this->descriptionWithTemplate($page, $metaDescription, $locale, $breadcrumbs),
            'canonical' => $canonical,
            'ogImage' => $ogImage,
            'ogImageAlt' => $image['alt'] ?? null,
            'ogImageType' => $image['mime'] ?? null,
            'ogTitle' => $ogTitle,
            'ogDescription' => $ogDescription,
            'noindex' => ! $page->is_indexable || ! $this->indexing->enabled(),
            'siteName' => $siteName,
            'ogType' => $isArticle ? 'article' : 'website',
            'ogUrl' => $canonical,
            'ogLocale' => $locale,
            'ogLocaleAlternates' => $this->ogLocaleAlternates($locale, $alternates),
            'articlePublishedTime' => $isArticle ? $this->articlePublishedTime($page) : null,
            'articleModifiedTime' => $isArticle ? $this->articleModifiedTime($page) : null,
            'articleAuthor' => $isArticle ? $this->articleAuthor($seo, $locale) : null,
            'twitterCard' => $this->twitterCard($ogImage),
            'alternates' => $alternates,
            'xDefault' => $this->xDefault($alternates),
            // metaTitle is already the page's own cascade (seo.title → title →
            // name) with no site-wide filler in it; the description cascade
            // has one more rung, so it is asked for separately below.
            'ownTitle' => $metaTitle,
            'ownDescription' => $this->ownDescription($page, $seo, $locale),
            'favicon' => $this->favicon(),
            'verificationGoogle' => VerificationToken::google()->fromSettings($this->settings, 'seo.verification.google'),
            'verificationBing' => VerificationToken::bing()->fromSettings($this->settings, 'seo.verification.bing'),
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

    /**
     * Mirrors PageSchema::dates()'s datePublished gate exactly: a draft or
     * expired page reached through admin preview must not advertise a
     * publication date via article:published_time either. Keep the two in
     * sync if this rule ever changes.
     */
    private function articlePublishedTime(Page $page): ?string
    {
        return $page->published_at !== null && $page->isLive()
            ? $page->published_at->toIso8601String()
            : null;
    }

    /** Mirrors PageSchema::dates()'s dateModified — unconditional, unlike published. */
    private function articleModifiedTime(Page $page): ?string
    {
        return $page->updated_at?->toIso8601String();
    }

    /** @param  array<string, mixed>  $seo */
    private function articleAuthor(array $seo, string $locale): ?string
    {
        $schema = is_array($seo['schema'] ?? null) ? $seo['schema'] : [];
        $author = LocalizedSeo::value($schema['author'] ?? null, $locale);

        return $author !== '' ? $author : null;
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

        // The Identity tab no longer exposes name/logo fields directly, so
        // both fall back to the site's own identity — a saved seo.identity.*
        // value from before that change, or written by a script, still wins.
        $logoId = $this->settings->get('seo.identity.logo');

        if (! is_string($logoId) || $logoId === '') {
            $logoId = $this->settings->get('site.logo');
        }

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
        return PageOwnSeo::title($page, $seo, $locale);
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
     * Renders only the title, for duplicate checks that must follow the same
     * template rules without paying for a full SEO resolution. Derives its
     * own base the same way resolve() does, so callers don't re-derive it.
     *
     * Breadcrumbs arrive as a closure because these callers render one title
     * per page across the whole site, and resolving a page's ancestors costs
     * a recursive CTE plus a page_slugs read. Only {parent} needs them, and
     * most templates do not use it — so the closure runs only when the
     * template actually references it.
     *
     * @param  Closure(): list<Breadcrumb>  $breadcrumbs
     */
    public function renderedTitle(Page $page, string $locale, Closure $breadcrumbs): string
    {
        $base = PageOwnSeo::title($page, PageOwnSeo::blob($page), $locale);
        $template = $this->templates->title($page->type, $locale);

        if ($template === '') {
            return $this->titleWithSuffix($base);
        }

        return $this->renderTemplate(
            $template,
            ['{title}' => $base],
            $locale,
            $page,
            str_contains($template, '{parent}') ? $breadcrumbs() : [],
        );
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
        $override = $this->validCanonicalOverride($seo, $locale);

        if ($override !== null) {
            return $override;
        }

        return url($this->url->build((string) $page->pathFor($locale), $locale));
    }

    /**
     * The stored override for this locale, or null when there isn't one or it
     * doesn't survive validation. Public because SitemapEntryBuilder needs the
     * exact same "is this a usable override" answer to decide whether a locale
     * has a non-self canonical pointing elsewhere — duplicating the check
     * there would let the two drift.
     *
     * Re-validated here rather than trusted from the stored value: the form
     * rule only covers what an editor types going forward (see
     * PageForm::seoFields()), but a row can carry a value written before the
     * rule existed, saved via a legacy scalar format, or written straight to
     * the database. A scheme other than http/https (a stray `javascript:` or
     * `mailto:`) is worse than no override at all, so it is treated as absent
     * rather than published as-is.
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
     * og_image, page.image, and seo.default_og_image are all $s->media()
     * fields — each stores a media id, not a URL, so all three go through
     * the same id-to-url resolver. Fallback order: the page's explicit
     * og_image override, then the page's own image, then the global default.
     * og_image itself is NOT translatable (see PageForm::seoFields()) — an
     * image isn't per-locale, so it's read as a plain scalar.
     *
     * Returns alt/mime alongside the URL — og:image:alt and og:image:type
     * must follow whichever image actually resolved (override, own, or
     * global default), and MediaUrlResolver::details() is the one query that
     * already has both on hand.
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
        if (self::namesAnImage($page, $seo)) {
            return $this->ownImageDetails($page, $seo);
        }

        $global = $this->settings->get('seo.default_og_image');

        return is_string($global) && $global !== '' ? $this->imageDetails($global) : null;
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
        return PageOwnSeo::namesImage($page, $seo);
    }

    /**
     * The image this page carries itself, with no site-wide fallback.
     *
     * Public because the sitemap needs exactly this and not ogImageDetails():
     * the global default exists so a share card is never blank, but listing
     * it against every URL in the sitemap would tell a crawler the same
     * image belongs to the whole site. A page with no image of its own is
     * better listed with none.
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
     * The one place a media id becomes an og:image URL + alt + mime. Mime is
     * dropped (not merely nulled) unless it starts with `image/` — the
     * library also stores PDFs and similar, and a non-image mime published
     * as og:image:type is worse than omitting the tag entirely.
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

    /**
     * The description cascade without its last rung.
     *
     * seo.default_description is a single string served on every page that
     * has none of its own — for a search engine that is the same duplicate
     * content a template is, so a page falling back to it has nothing of its
     * own to say and must not be reported as filled in.
     *
     * @param  array<string, mixed>  $seo
     */
    private function ownDescription(Page $page, array $seo, string $locale): string
    {
        return PageOwnSeo::description($page, $seo, $locale);
    }
}
