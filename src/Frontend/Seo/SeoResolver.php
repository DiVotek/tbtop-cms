<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Closure;
use Tbtop\Cms\Block\MediaUrlResolver;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContextFactory;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaSelector;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeInterface;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\PageUrl;
use Tbtop\Cms\Settings\Settings;

/**
 * @phpstan-type Alternate array{locale: string, url: string}
 * @phpstan-type Breadcrumb array{label: string, path: string, url: string}
 */
class SeoResolver
{
    private readonly IndexingSetting $indexing;

    private readonly SeoTemplates $templates;

    private readonly SchemaContextFactory $factory;

    public function __construct(
        private readonly Settings $settings,
        private readonly PageUrl $url,
        private readonly Languages $languages,
        private readonly JsonLd $jsonLd,
        private readonly Breadcrumbs $breadcrumbs,
        private readonly PageTypeRegistry $pageTypes,
        private readonly SchemaSelector $schemaSelector,
        private readonly MediaUrlResolver $mediaUrlResolver = new MediaUrlResolver,
        ?IndexingSetting $indexing = null,
        ?SeoTemplates $templates = null,
        ?SchemaContextFactory $factory = null,
    ) {
        // Built from the same Settings instance rather than resolved from the
        // container: this resolver is constructed directly in places that pass
        // a purpose-built Settings, and a container-resolved reader there would
        // consult a different one.
        $this->indexing = $indexing ?? new IndexingSetting($settings);
        $this->templates = $templates ?? new SeoTemplates($settings);
        $this->factory = $factory ?? new SchemaContextFactory($settings, $url, $pageTypes, $mediaUrlResolver);
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
     *                                         this stays a single query per request. Feeds {parent}
     *                                         and nothing else — BreadcrumbList builds its own trail
     *                                         (see jsonLd()), so passing [] does not suppress it.
     * @param  ?SchemaContext  $ctx  Built via SchemaContextFactory when omitted — pass one when the
     *                               caller already built it (e.g. to resolve block schema nodes
     *                               against the same context this call publishes).
     * @param  list<array<string, mixed>>  $blockNodes  Ready JSON-LD nodes (e.g. from Block::toSchema()),
     *                                                  placed after the page type's own node(s) and
     *                                                  before the identity node.
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
    public function resolve(Page $page, string $locale, array $breadcrumbs = [], ?SchemaContext $ctx = null, array $blockNodes = []): array
    {
        $seo = $page->seo ?? [];
        $metaTitle = $this->metaTitle($page, $seo, $locale);
        $metaDescription = $this->description($page, $seo, $locale);
        $ogTitle = LocalizedSeo::value($seo['og_title'] ?? null, $locale) ?: $metaTitle;
        $siteName = (string) $this->settings->get('site.name', '');
        $alternates = $this->alternates($page);
        $schema = $this->schemaSelector->selectFor($page);
        $context = $ctx ?? $this->factory->make($page, $locale);
        $og = $schema->openGraph($context);

        return [
            'title' => $this->title($page, $metaTitle, $locale, $breadcrumbs),
            'description' => $this->descriptionWithTemplate($page, $metaDescription, $locale, $breadcrumbs),
            'canonical' => $context->url,
            'ogImage' => $context->image,
            'ogImageAlt' => $context->imageAlt,
            'ogImageType' => $context->imageMime,
            'ogTitle' => $ogTitle,
            'ogDescription' => $context->description,
            'noindex' => ! $page->is_indexable || ! $this->indexing->enabled(),
            'siteName' => $siteName,
            'ogType' => $og->type,
            'ogUrl' => $context->url,
            'ogLocale' => $locale,
            'ogLocaleAlternates' => $this->ogLocaleAlternates($locale, $alternates),
            'articlePublishedTime' => $og->publishedTime,
            'articleModifiedTime' => $og->modifiedTime,
            'articleAuthor' => $og->author,
            'twitterCard' => $this->twitterCard($context->image),
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
                $context->url,
                $schema,
                $context,
                $blockNodes,
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
     * @param  list<array<string, mixed>>  $blockNodes
     * @return list<array<string, mixed>>
     */
    private function jsonLd(
        Page $page,
        string $locale,
        string $siteName,
        string $ogTitle,
        string $canonical,
        SchemaTypeInterface $schema,
        SchemaContext $context,
        array $blockNodes = [],
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
        // Not resolve()'s $breadcrumbs: that one must stay on the real
        // ancestry for {parent}. See Breadcrumbs::trailForSchema().
        $breadcrumbList = $this->jsonLd->breadcrumbList(
            $this->breadcrumbs->trailForSchema($page, $locale),
            $currentLabel !== '' ? $currentLabel : $ogTitle,
            $canonical,
        );

        if ($breadcrumbList !== null) {
            $objects[] = $breadcrumbList;
        }

        foreach ($schema->build($context) as $node) {
            $objects[] = $node;
        }

        foreach ($blockNodes as $node) {
            $objects[] = $node;
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
        // Shared with SchemaContextFactory::identityAuthor() via the same
        // method, so the identity node and the Article author name the same
        // entity.
        $name = $this->factory->identityName();

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

    /**
     * Renders only the description, mirroring renderedTitle() for duplicate
     * checks that must follow the same template rules without paying for a
     * full SEO resolution. Derives its own base through the same private
     * description() cascade resolve() uses, so this and resolve() share one
     * implementation of the base rather than two copies drifting apart; the
     * template step below is descriptionWithTemplate()'s logic with lazy
     * breadcrumbs, same relationship renderedTitle() has to title().
     *
     * Breadcrumbs arrive lazily for the same reason as renderedTitle(): only
     * a template referencing {parent} needs the ancestor query.
     *
     * @param  Closure(): list<Breadcrumb>  $breadcrumbs
     */
    public function renderedDescription(Page $page, string $locale, Closure $breadcrumbs): string
    {
        $seo = PageOwnSeo::blob($page);
        $base = $this->description($page, $seo, $locale);
        $template = $this->templates->description($page->type, $locale);

        if ($template === '') {
            return $base;
        }

        return $this->renderTemplate(
            $template,
            ['{description}' => $base],
            $locale,
            $page,
            str_contains($template, '{parent}') ? $breadcrumbs() : [],
        );
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
     * SchemaContext::baseNode() applies: a draft or expired page reached through
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

    /**
     * Delegates to SchemaContextFactory: SitemapEntryBuilder needs the exact
     * same "is this a usable override" answer to decide whether a locale has
     * a non-self canonical pointing elsewhere, and the factory is the one
     * place that computation lives.
     *
     * @param  array<string, mixed>  $seo
     */
    public function validCanonicalOverride(array $seo, string $locale): ?string
    {
        return $this->factory->validCanonicalOverride($seo, $locale);
    }

    /**
     * Delegates to SchemaContextFactory. Public because the sitemap needs
     * exactly the page's own image, with no site-wide fallback — listing the
     * global default against every sitemap URL would claim one image
     * belongs to the whole site.
     *
     * @param  array<string, mixed>  $seo
     */
    public function ownImage(Page $page, array $seo): ?string
    {
        return $this->factory->ownImage($page, $seo);
    }

    /** @param array<string, mixed> $seo */
    private function description(Page $page, array $seo, string $locale): string
    {
        return $this->factory->description($page, $seo, $locale);
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
