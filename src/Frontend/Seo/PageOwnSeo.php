<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageTranslation;

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
     * a page that has one in this locale. The whole chain runs in this
     * locale before the default one: this locale's name beats the default
     * language's title, or a translated page ships an untranslated <title>.
     *
     * @param  array<string, mixed>  $seo
     */
    public static function title(Page $page, array $seo, string $locale): string
    {
        return LocalizedSeo::value($seo['title'] ?? null, $locale)
            ?: PageTranslation::firstText($page, ['title', 'name'], $locale, self::fallbackLocale());
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
            ?: (string) PageTranslation::text($page, 'summary', $locale, self::fallbackLocale());
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

    /** The site's default language: Page::getTranslation()'s own fallback is app.fallback_locale, and it keeps a blank ''. */
    private static function fallbackLocale(): string
    {
        return app(Languages::class)->defaultLocale();
    }

    /** @return array<string, mixed> */
    public static function blob(Page $page): array
    {
        return is_array($page->seo) ? $page->seo : [];
    }
}
