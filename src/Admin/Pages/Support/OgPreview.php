<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\LiveRegionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Page\Models\Page;

/**
 * Social-share card preview above the OG fields: the image, title and
 * description a link to this page would show when shared, re-rendered live
 * as the editor types.
 *
 * The strings come from SeoResolver applied to an unsaved overlay of the page
 * (see draftOf()), not from the raw field values — so the preview shows the
 * og:title/og:description fallback cascade (override → meta title/description)
 * exactly as a crawler would read it, which is what the editor cannot
 * otherwise see without saving.
 */
final class OgPreview
{
    /**
     * The card is resolved for every content locale at once and the client
     * picks the one whose tab is active — same reasoning as SerpPreview::
     * build().
     *
     * og_image is not translatable (see PageForm::seoFields()), so it is
     * declared once as a plain scalar dep rather than per locale.
     *
     * seo_title/seo_description are deps too, not just the og_* fields:
     * og:title/og:description fall back to the untemplated meta title/
     * description when their own override is empty (SeoResolver::resolve()'s
     * ogTitle/ogDescription) — and empty og fields are the common state, so
     * without those deps the card would sit on the saved fallback while the
     * editor types a new meta title the crawler would actually receive.
     *
     * @return list<LiveRegionBuilder>
     */
    public static function build(S $s, ?Page $page, string $defaultLocale): array
    {
        if ($page === null || ! $page->exists) {
            return [];
        }

        $locales = EditorLocales::list($defaultLocale);

        $deps = ['og_image'];
        foreach ($locales as $locale) {
            $deps[] = "og_title.{$locale}";
            $deps[] = "og_description.{$locale}";
            $deps[] = "seo_title.{$locale}";
            $deps[] = "seo_description.{$locale}";
        }

        $region = $s->liveRegion('ogPreview')
            ->dependsOn($deps)
            ->render(static fn (array $bag): Node => self::card($page, $locales, $defaultLocale, $bag));

        // The builder itself, not ->toNode() — same reason as SerpPreview: the
        // live-region endpoint resolves the region by walking the form tree
        // for a LiveRegionBuilder, so a pre-serialized node would render once
        // and then 404 on every subsequent dependency change.
        return [$region];
    }

    /**
     * A custom node kind rather than display blocks — same reasoning as
     * SerpPreview::card(). The matching renderer is registered by
     * resources/js/cms/admin/blocks.ts.
     *
     * og_image resolves to the same image for every locale (it is not
     * translatable), so it is resolved once here rather than once per
     * locale inside the loop below.
     *
     * Values travel as data, not markup, so nothing here needs escaping: React
     * renders them as text nodes.
     *
     * @param  list<string>  $locales
     * @param  array<string, string>  $bag
     */
    private static function card(Page $page, array $locales, string $defaultLocale, array $bag): Node
    {
        $resolver = app(SeoResolver::class);
        $breadcrumbs = app(Breadcrumbs::class);
        $draft = self::draftOf($page, $locales, $bag);

        $byLocale = [];
        $shared = null;

        foreach ($locales as $locale) {
            $resolved = $resolver->resolve($draft, $locale, $breadcrumbs->forPage($page, $locale));

            $byLocale[$locale] = [
                'title' => $resolved['ogTitle'],
                'description' => $resolved['ogDescription'],
            ];

            // Image, host and site name do not vary by locale — resolved once
            // from whichever locale happens to run first rather than repeating
            // the same computation on every pass through the loop.
            if ($shared === null) {
                $shared = [
                    'image' => $resolved['ogImage'] === null ? null : [
                        'url' => $resolved['ogImage'],
                        'alt' => $resolved['ogImageAlt'],
                    ],
                    'host' => (string) parse_url($resolved['ogUrl'], PHP_URL_HOST),
                    'siteName' => $resolved['siteName'],
                ];
            }
        }

        return new Node('cms:ogPreview', [
            'byLocale' => $byLocale,
            'image' => $shared['image'] ?? null,
            'host' => $shared['host'] ?? '',
            'siteName' => $shared['siteName'] ?? '',
            'defaultLocale' => $defaultLocale,
            'emptyTitle' => __('tbtop-cms::pages.og_preview.empty_title'),
            'emptyDescription' => __('tbtop-cms::pages.og_preview.empty_description'),
            'labels' => [
                'sectionTitle' => __('tbtop-cms::pages.og_preview.section_title'),
                'noImageHint' => __('tbtop-cms::pages.og_preview.no_image_hint'),
            ],
        ]);
    }

    /**
     * A detached copy of the page carrying the form's unsaved og_title/
     * og_description/og_image overrides, so resolve() runs its real fallback
     * cascade against what the editor is typing. replicate() drops the key,
     * which pathFor()/alternates() need, so identity is restored and the copy
     * is never saved.
     *
     * @param  list<string>  $locales
     * @param  array<string, string>  $deps
     */
    private static function draftOf(Page $page, array $locales, array $deps): Page
    {
        $draft = $page->replicate();
        $draft->setRelations($page->getRelations());
        $draft->exists = true;
        $draft->setAttribute($page->getKeyName(), $page->getKey());

        $seo = is_array($page->seo) ? $page->seo : [];

        foreach ($locales as $locale) {
            // seo_* overlays feed the fallback cascade og:title/og:description
            // resolve through when their own override is empty (see build()).
            $map = ['og_title' => 'og_title', 'og_description' => 'og_description', 'title' => 'seo_title', 'description' => 'seo_description'];
            foreach ($map as $key => $dep) {
                $value = $deps["{$dep}.{$locale}"] ?? null;

                if ($value !== null) {
                    $existing = is_array($seo[$key] ?? null) ? $seo[$key] : [];
                    $seo[$key] = [...$existing, $locale => $value];
                }
            }
        }

        if (array_key_exists('og_image', $deps)) {
            $seo['og_image'] = $deps['og_image'];
        }

        $draft->seo = $seo;

        return $draft;
    }
}
