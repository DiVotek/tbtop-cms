<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Page\Models\Page;

/**
 * What a page says about itself, per locale, from the row already in hand —
 * no templates, no alternates, no site-wide fallbacks, no queries.
 *
 * SeoResolver::resolve() is the deep entry point but an expensive one, so
 * the report and the checklist (which loop every page × every locale) used
 * to re-derive these cascades by hand instead of paying for it. Splitting
 * the cheap half out means one rule per question, shared by the renderer
 * and everything that reasons about it.
 */
final class PageOwnSeo
{
    /**
     * Meta title, falling back to the editorial title and then the name —
     * name is the only required editorial field, so this is never empty for
     * a page that has one in this locale.
     *
     * @param  array<string, mixed>  $seo
     */
    public static function title(Page $page, array $seo, string $locale): string
    {
        return LocalizedSeo::value($seo['title'] ?? null, $locale)
            ?: (string) $page->getTranslation('title', $locale)
            ?: (string) $page->getTranslation('name', $locale);
    }

    /**
     * Meta description, falling back to the summary. Deliberately excludes
     * the site-wide filler: a page with neither has nothing of its own to
     * say and must not be reported as filled in.
     *
     * @param  array<string, mixed>  $seo
     */
    public static function description(Page $page, array $seo, string $locale): string
    {
        return LocalizedSeo::value($seo['description'] ?? null, $locale)
            ?: (string) $page->getTranslation('summary', $locale);
    }

    /**
     * Whether the page named an og:image itself. A broken id still counts:
     * the editor made a choice, and ogImageDetails() honours it rather than
     * substituting the site-wide default.
     *
     * @param  array<string, mixed>  $seo
     */
    public static function namesImage(Page $page, array $seo): bool
    {
        $override = $seo['og_image'] ?? null;

        return (is_string($override) && $override !== '')
            || ($page->image !== null && $page->image !== '');
    }

    /** @return array<string, mixed> */
    public static function blob(Page $page): array
    {
        return is_array($page->seo) ? $page->seo : [];
    }
}
