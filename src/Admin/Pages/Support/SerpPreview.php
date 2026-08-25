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
 * Search-result preview above the SEO fields: the title, URL and snippet a
 * page would show in a result list, re-rendered live as the editor types.
 *
 * The strings come from SeoResolver applied to an unsaved overlay of the page
 * (see draftOf()), not from the raw field values — so the preview shows the
 * final text after the title template and suffix, which is what a search
 * engine actually receives and what the editor cannot otherwise see without
 * saving.
 */
final class SerpPreview
{
    /**
     * The card is resolved for every content locale at once and the client
     * picks the one whose tab is active, the same way a translatable field
     * holds its whole locale map and shows one key of it.
     *
     * A single-locale card would have to be recomputed on the server every
     * time the editor switches tabs, and that switch never reaches the server:
     * the active tab is form state in the browser and is not a form value, so
     * it cannot appear in the deps bag. Resolving all of them costs one extra
     * pass per locale over data already loaded, and buys a preview that
     * follows the tabs.
     *
     * og_* are deliberately absent from the deps: they drive the social card,
     * not the search result, and a dependency that never changes the output
     * would just cost an extra round-trip per keystroke.
     *
     * @return list<LiveRegionBuilder>
     */
    public static function build(S $s, ?Page $page, string $defaultLocale): array
    {
        if ($page === null || ! $page->exists) {
            return [];
        }

        $locales = EditorLocales::list($defaultLocale);

        $deps = [];
        foreach ($locales as $locale) {
            $deps[] = "seo_title.{$locale}";
            $deps[] = "seo_description.{$locale}";
            $deps[] = "seo_canonical.{$locale}";
        }

        $region = $s->liveRegion('serpPreview')
            ->dependsOn($deps)
            ->render(static fn (array $bag): Node => self::card($page, $locales, $defaultLocale, $bag));

        // No wrapping section: the block draws its own header row, because the
        // collapsed state has to show the meters, and those are measured in the
        // browser. A section title above it would repeat the same words the
        // block's own toggle carries.
        //
        // The builder itself, not ->toNode(): the live-region endpoint resolves
        // the region by walking the form tree for a LiveRegionBuilder
        // (FormBuilder::searchLiveRegion), so a pre-serialized node renders
        // once and then 404s on every subsequent dependency change.
        return [$region];
    }

    /**
     * A custom node kind rather than display blocks: the card measures the
     * rendered pixel width of the title (which is how a result list actually
     * truncates it, and what no server can compute) and switches between the
     * desktop and mobile layouts, both of which need client state. The
     * matching renderer is registered by resources/js/cms/admin/blocks.ts —
     * without that import the client shows its unknown-kind placeholder.
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

        $byLocale = [];
        foreach ($locales as $locale) {
            // Passed through so a {parent} template resolves here exactly as
            // it does on the frontend — a preview that silently drops a
            // segment the live page shows is worse than no preview.
            $resolved = $resolver->resolve(
                self::draftOf($page, $locale, $bag),
                $locale,
                $breadcrumbs->forPage($page, $locale),
            );

            $byLocale[$locale] = [
                'url' => self::displayUrl($resolved['canonical']),
                'title' => $resolved['title'],
                'description' => $resolved['description'],
            ];
        }

        return new Node('cms:serpPreview', [
            'byLocale' => $byLocale,
            'defaultLocale' => $defaultLocale,
            'emptyTitle' => __('tbtop-cms::pages.serp_preview.empty_title'),
            'emptyDescription' => __('tbtop-cms::pages.serp_preview.empty_description'),
            'labels' => [
                'desktop' => __('tbtop-cms::pages.serp_preview.device.desktop'),
                'mobile' => __('tbtop-cms::pages.serp_preview.device.mobile'),
                'titleWidth' => __('tbtop-cms::pages.serp_preview.meter.title_width'),
                'descriptionLength' => __('tbtop-cms::pages.serp_preview.meter.description_length'),
                'truncated' => __('tbtop-cms::pages.serp_preview.meter.truncated'),
                'sectionTitle' => __('tbtop-cms::pages.serp_preview.section_title'),
                'summary' => __('tbtop-cms::pages.serp_preview.summary'),
                'expand' => __('tbtop-cms::pages.serp_preview.expand'),
                'collapse' => __('tbtop-cms::pages.serp_preview.collapse'),
            ],
        ]);
    }

    /**
     * Search results show the path as breadcrumbs rather than a bare URL; the
     * scheme carries no information for the editor and eats a line on narrow
     * columns, so it is dropped and the separators widened.
     */
    private static function displayUrl(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($host === '') {
            return $url;
        }

        return $path === '' ? $host : $host . ' › ' . str_replace('/', ' › ', $path);
    }

    /**
     * A detached copy of the page carrying the form's unsaved SEO overrides,
     * so resolve() runs its real cascade (override → page field → setting,
     * then template and suffix) against what the editor is typing. replicate()
     * drops the key, which pathFor()/alternates() need, so identity is
     * restored and the copy is never saved.
     *
     * @param  array<string, string>  $deps
     */
    private static function draftOf(Page $page, string $locale, array $deps): Page
    {
        return PageSeoDraft::make(
            $page,
            $deps,
            ['title' => 'seo_title', 'description' => 'seo_description', 'canonical' => 'seo_canonical'],
            [$locale],
        );
    }
}
