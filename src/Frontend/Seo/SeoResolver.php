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
use Tbtop\Cms\Settings\SiteContacts;

/**
 * @phpstan-type Alternate array{locale: string, url: string}
 * @phpstan-type Breadcrumb array{label: string, path: string, url: string}
 */
class SeoResolver
{
    private readonly IndexingSetting $indexing;

    private readonly SeoTemplates $templates;

    private readonly SchemaContextFactory $factory;

    private readonly SeoTextRenderer $text;

    private readonly SeoAlternates $alternates;

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
        $this->text = new SeoTextRenderer($settings, $this->templates, $this->factory);
        $this->alternates = new SeoAlternates($url, $languages);
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
     * @param  list<Breadcrumb>  $breadcrumbs  Real ancestry (Breadcrumbs::ancestors()), NOT the
     *                                         `breadcrumbs` prop / Cms::breadcrumbs() trail, which
     *                                         starts at home and ends at the page itself. Feeds
     *                                         {parent} and nothing else — BreadcrumbList builds its
     *                                         own trail (see jsonLd()), so passing [] does not suppress it.
     * @param  ?SchemaContext  $ctx  Built via SchemaContextFactory when omitted — pass one when the
     *                               caller already built it (e.g. to resolve block schema nodes
     *                               against the same context this call publishes).
     * @param  list<array<string, mixed>>  $blockNodes  Ready JSON-LD nodes (e.g. from Block::toSchema()),
     *                                                  placed after the page type's own node(s) and
     *                                                  before the identity node.
     * @return array{
     *     title: string, description: string, canonical: string, ogImage: string|null,
     *     ogImageAlt: string|null, ogImageType: string|null, ogImageWidth: int|null, ogImageHeight: int|null,
     *     ogTitle: string, ogDescription: string, noindex: bool, siteName: string,
     *     ogType: string, ogUrl: string, ogLocale: string|null, ogLocaleAlternates: list<string>,
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
        $alternates = $this->alternates->forPage($page);
        $schema = $this->schemaSelector->selectFor($page);
        $context = $ctx ?? $this->factory->make($page, $locale);
        $og = $schema->openGraph($context);

        return [
            'title' => $this->text->title($page, $metaTitle, $locale, $breadcrumbs),
            'description' => $this->text->description($page, $metaDescription, $locale, $breadcrumbs),
            'canonical' => $context->url,
            'ogImage' => $context->image,
            'ogImageAlt' => $context->imageAlt,
            'ogImageType' => $context->imageMime,
            'ogImageWidth' => $context->imageWidth,
            'ogImageHeight' => $context->imageHeight,
            'ogTitle' => $ogTitle,
            'ogDescription' => $context->description,
            'noindex' => ! $page->is_indexable || ! $this->indexing->enabled(),
            'siteName' => $siteName,
            'ogType' => $og->type,
            'ogUrl' => $context->url,
            'ogLocale' => $this->languages->ogLocale($locale),
            'ogLocaleAlternates' => $this->alternates->ogLocales($locale, $alternates),
            'articlePublishedTime' => $og->publishedTime,
            'articleModifiedTime' => $og->modifiedTime,
            'articleAuthor' => $og->author,
            'twitterCard' => $this->twitterCard($context->image),
            'alternates' => $alternates,
            'xDefault' => $this->alternates->xDefault($alternates),
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

        // The last crumb is re-added with the canonical URL (an editor may
        // override it); resolve()'s $breadcrumbs is only ancestry for {parent}.
        $trail = $this->breadcrumbs->forPage($page, $locale);
        $currentLabel = $trail === [] ? '' : $trail[count($trail) - 1]['label'];
        $breadcrumbList = $this->jsonLd->breadcrumbList(
            array_slice($trail, 0, -1),
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

        $identity = $this->identity($locale);

        if ($identity !== null) {
            $objects[] = $identity;
        }

        return $objects;
    }

    /**
     * One node for the whole site, @id-linked to the Article author and
     * publisher (SchemaContextFactory) so consumers merge them into one
     * entity. $locale only picks the translatable address parts.
     *
     * @return array<string, mixed>|null
     */
    private function identity(string $locale): ?array
    {
        $identity = new SiteIdentity($this->settings);
        // Shared with SchemaContextFactory::identityAuthor() via the same
        // method, so the identity node and the Article author name the same
        // entity.
        $name = $this->factory->identityName();

        $logo = $this->factory->identityLogo();

        return $this->jsonLd->identity(
            $identity->type(),
            $name,
            url('/'),
            $logo,
            $this->sameAs(),
            $identity->schemaType(),
            SiteIdentity::id(),
            $identity->properties($locale, $this->languages->defaultLocale()),
        );
    }

    /**
     * Re-checked at emit time: the Contacts form only validates on save, so
     * a programmatically-written setting could hold anything — non-URL
     * entries must never be published as sameAs structured data.
     *
     * @return list<string>
     */
    private function sameAs(): array
    {
        // Site settings → Contacts is the only source of profile links.
        return array_values(array_filter(
            array_column((new SiteContacts($this->settings))->socials(), 'href'),
            ProfileUrl::valid(...),
        ));
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
     * Renders only the title, for duplicate checks that must follow the same
     * template rules without paying for a full SEO resolution — see
     * SeoTextRenderer::renderedTitle().
     *
     * @param  Closure(): list<Breadcrumb>  $breadcrumbs
     */
    public function renderedTitle(Page $page, string $locale, Closure $breadcrumbs): string
    {
        return $this->text->renderedTitle($page, $locale, $breadcrumbs);
    }

    /**
     * Renders only the description, mirroring renderedTitle() — see
     * SeoTextRenderer::renderedDescription().
     *
     * @param  Closure(): list<Breadcrumb>  $breadcrumbs
     */
    public function renderedDescription(Page $page, string $locale, Closure $breadcrumbs): string
    {
        return $this->text->renderedDescription($page, $locale, $breadcrumbs);
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
