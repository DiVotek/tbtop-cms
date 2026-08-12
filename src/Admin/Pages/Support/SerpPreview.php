<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\LiveRegionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
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
     * The preview depends on the SEO overrides for the previewed locale only.
     * og_* are deliberately absent: they drive the social card, not the search
     * result, and a dependency that never changes the output would just cost
     * an extra round-trip per keystroke.
     *
     * $locale is fixed at render time, so the card keeps showing that locale
     * while the editor switches the locale tabs above it — which locale tab is
     * active is client state and never reaches the deps bag.
     *
     * @return list<LiveRegionBuilder>
     */
    public static function build(S $s, ?Page $page, string $locale): array
    {
        if ($page === null || ! $page->exists) {
            return [];
        }

        $region = $s->liveRegion('serpPreview')
            ->dependsOn(["seo_title.{$locale}", "seo_description.{$locale}", "seo_canonical.{$locale}"])
            ->render(static fn (array $deps): Node => self::card($page, $locale, $deps));

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
     * @param  array<string, string>  $deps
     */
    private static function card(Page $page, string $locale, array $deps): Node
    {
        $resolved = app(SeoResolver::class)->resolve(self::draftOf($page, $locale, $deps), $locale);

        return new Node('cms:serpPreview', [
            'url' => self::displayUrl($resolved['canonical']),
            'title' => $resolved['title'],
            'description' => $resolved['description'],
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
        $draft = $page->replicate();
        $draft->setRelations($page->getRelations());
        $draft->exists = true;
        $draft->setAttribute($page->getKeyName(), $page->getKey());

        $seo = is_array($page->seo) ? $page->seo : [];

        foreach (['title' => 'seo_title', 'description' => 'seo_description', 'canonical' => 'seo_canonical'] as $key => $dep) {
            $value = $deps["{$dep}.{$locale}"] ?? null;

            if ($value !== null) {
                $existing = is_array($seo[$key] ?? null) ? $seo[$key] : [];
                $seo[$key] = [...$existing, $locale => $value];
            }
        }

        $draft->seo = $seo;

        return $draft;
    }
}
