<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Closure;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContextFactory;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

/**
 * Applies the title and description templates (`seo.title_template`, the
 * per-type templates, the legacy suffix) to a page's own title and
 * description. SeoResolver renders through it for the page props; the
 * duplicate checks call renderedTitle()/renderedDescription() through
 * SeoResolver without a full resolution.
 *
 * @phpstan-import-type Breadcrumb from SeoResolver
 */
final class SeoTextRenderer
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SeoTemplates $templates,
        private readonly SchemaContextFactory $factory,
    ) {}

    /**
     * The template comes from SeoTemplates: the page type's own template if
     * one is set for this locale, otherwise the site-wide `seo.title_template`
     * (translatable). Either one supersedes the legacy single-space suffix —
     * existing sites that never touch these settings must see byte-identical
     * titles, so an empty template falls straight back to append-suffix.
     *
     * @param  list<Breadcrumb>  $breadcrumbs
     */
    public function title(Page $page, string $base, string $locale, array $breadcrumbs): string
    {
        $template = $this->templates->title($page->type, $locale, $page->subtype);

        if ($template === '') {
            return $this->titleWithSuffix($base);
        }

        return $this->renderTemplate($template, ['{title}' => $base], $locale, $page, $breadcrumbs);
    }

    /**
     * Renders only the title, for duplicate checks that must follow the same
     * template rules without paying for a full SEO resolution. Derives its
     * own base the same way SeoResolver::resolve() does, so callers don't re-derive it.
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
        $template = $this->templates->title($page->type, $locale, $page->subtype);

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
    public function description(Page $page, string $base, string $locale, array $breadcrumbs): string
    {
        $template = $this->templates->description($page->type, $locale, $page->subtype);

        if ($template === '') {
            return $base;
        }

        return $this->renderTemplate($template, ['{description}' => $base], $locale, $page, $breadcrumbs);
    }

    /**
     * Renders only the description, mirroring renderedTitle() for duplicate
     * checks that must follow the same template rules without paying for a
     * full SEO resolution. Derives its own base through the same
     * SchemaContextFactory::description() cascade SeoResolver::resolve()
     * uses, so the two share one implementation of the base rather than two
     * copies drifting apart; the template step below is description()'s logic
     * with lazy breadcrumbs, same relationship renderedTitle() has to title().
     *
     * Breadcrumbs arrive lazily for the same reason as renderedTitle(): only
     * a template referencing {parent} needs the ancestor query.
     *
     * @param  Closure(): list<Breadcrumb>  $breadcrumbs
     */
    public function renderedDescription(Page $page, string $locale, Closure $breadcrumbs): string
    {
        $seo = PageOwnSeo::blob($page);
        $base = $this->factory->description($page, $seo, $locale);
        $template = $this->templates->description($page->type, $locale, $page->subtype);

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
}
