<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/**
 * Deterministic per-page SEO checks, rendered beside the page editor's SEO
 * fields (PageForm::seoFields()). Every check is a plain fact — no scoring
 * heuristic, no readability analysis.
 *
 * Length reporting stays out of here: the preview's meters already measure the
 * title in pixels and the description in characters, live as the editor types,
 * and a second character count in this list said the same number twice. What
 * these checks carry is what a meter cannot — whether the value exists at all,
 * and how it relates to the rest of the site.
 *
 * Reuses SeoResolver::resolve() rather than re-deriving the title/description/
 * canonical/og_image cascades a second time — SeoResolver is the single
 * source of truth for those, and its internals are private by design.
 *
 * @phpstan-type Check array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string, required: bool}
 */
class SeoChecklist
{
    public function __construct(
        private readonly SeoResolver $resolver,
        private readonly Languages $languages,
        private readonly Breadcrumbs $breadcrumbs,
    ) {}

    /** @return list<Check> */
    public function for(Page $page, string $locale): array
    {
        // With breadcrumbs: the focus-keyword checks read the RENDERED title,
        // and a {parent} segment is part of what a crawler sees — resolving
        // without them would hide a keyword the page genuinely carries (see
        // focusKeywordChecks()).
        $resolved = $this->resolver->resolve($page, $locale, $this->breadcrumbs->ancestors($page, $locale));
        $seo = is_array($page->seo) ? $page->seo : [];

        return [
            // Own values, not rendered ones: a title template appending the
            // site name makes every rendered title non-empty, so a page with
            // nothing filled in reported two greens (see resolve()'s docblock
            // for ownTitle/ownDescription).
            $this->titleCheck($resolved['ownTitle']),
            $this->descriptionCheck($resolved['ownDescription']),
            $this->ogImageCheck($resolved['ogImage']),
            $this->indexableCheck($page),
            $this->canonicalCheck($resolved['canonical']),
            $this->hreflangCheck($page),
            ...$this->focusKeywordChecks($page, $seo, $locale, $resolved),
            ...$this->duplicateChecks($page, $resolved['title'], $resolved['description'], $locale),
        ];
    }

    /**
     * Presence only. How long the title renders is the preview meter's job —
     * it measures the pixel width the result column actually cuts at, which is
     * both more accurate than a character count and updated as the editor
     * types. Reporting a second, coarser number here contradicted it on every
     * page whose title had wide or narrow letters.
     *
     * @return Check
     */
    private function titleCheck(string $title): array
    {
        return $title === ''
            ? $this->check('title', 'fail', __('tbtop-cms::pages.seo_checklist.title.fail'))
            : $this->check('title', 'pass', __('tbtop-cms::pages.seo_checklist.title.pass'));
    }

    /** @return Check */
    private function descriptionCheck(string $description): array
    {
        return $description === ''
            ? $this->check('description', 'fail', __('tbtop-cms::pages.seo_checklist.description.fail'))
            : $this->check('description', 'pass', __('tbtop-cms::pages.seo_checklist.description.pass'));
    }

    /**
     * Blank counts as missing, not just null — reporting an empty string as a
     * configured image is exactly the false reassurance this checklist exists
     * to prevent. MediaUrlResolver is what keeps a pathless media row from
     * reaching here as a bare directory URL.
     *
     * @return Check
     */
    private function ogImageCheck(?string $ogImage): array
    {
        return trim((string) $ogImage) === ''
            ? $this->check('og_image', 'warn', __('tbtop-cms::pages.seo_checklist.og_image.warn'))
            : $this->check('og_image', 'pass', __('tbtop-cms::pages.seo_checklist.og_image.pass'));
    }

    /**
     * A published page with noindex set is a legitimate configuration (a
     * thank-you page, a duplicate of another canonical page, …) — surfaced
     * as a warn, not a fail, purely so it isn't invisible.
     *
     * @return Check
     */
    private function indexableCheck(Page $page): array
    {
        if (! $page->is_indexable && $page->status === PageStatus::Published) {
            return $this->check('indexable', 'warn', __('tbtop-cms::pages.seo_checklist.indexable.warn'));
        }

        return $this->check('indexable', 'pass', __('tbtop-cms::pages.seo_checklist.indexable.pass'));
    }

    /**
     * Hostnames are case-insensitive but parse_url() preserves the casing it
     * was given, so `HTTPS://EXAMPLE.COM/` would otherwise warn against
     * `example.com` — a false alarm on the same host.
     *
     * @return Check
     */
    private function canonicalCheck(string $canonical): array
    {
        $canonicalHost = parse_url($canonical, PHP_URL_HOST);
        $siteHost = parse_url(url('/'), PHP_URL_HOST);

        $canonicalHost = is_string($canonicalHost) ? mb_strtolower($canonicalHost) : $canonicalHost;
        $siteHost = is_string($siteHost) ? mb_strtolower($siteHost) : $siteHost;

        if (is_string($canonicalHost) && is_string($siteHost) && $canonicalHost !== '' && $canonicalHost !== $siteHost) {
            return $this->check('canonical', 'warn', __('tbtop-cms::pages.seo_checklist.canonical.warn', ['host' => $canonicalHost]));
        }

        return $this->check('canonical', 'pass', __('tbtop-cms::pages.seo_checklist.canonical.pass'));
    }

    /**
     * Warns when the page has a slug in some, but not all, frontend-active
     * locales — a page fully absent from a locale is a normal, deliberate
     * choice (see SeoAlternates::forPage()), so only the partial case is
     * worth flagging.
     *
     * @return Check
     */
    private function hreflangCheck(Page $page): array
    {
        $activeLocales = array_map(
            static fn (array $row): string => $row['locale'],
            $this->languages->frontendActive(),
        );

        $present = array_values(array_filter(
            $activeLocales,
            static fn (string $locale): bool => $page->pathFor($locale) !== null,
        ));

        if ($present !== [] && count($present) < count($activeLocales)) {
            return $this->check('hreflang', 'warn', __('tbtop-cms::pages.seo_checklist.hreflang.warn', [
                'present' => implode(', ', $present),
                'missing' => implode(', ', array_values(array_diff($activeLocales, $present))),
            ]));
        }

        return $this->check('hreflang', 'pass', __('tbtop-cms::pages.seo_checklist.hreflang.pass'));
    }

    /**
     * Searched in the RENDERED title and description, unlike the presence
     * checks above.
     *
     * The two ask different questions. "Is this filled in?" is about the
     * page, so site-wide filler must not answer it. "Does the title carry the
     * keyword?" is about what a crawler reads — and a crawler reads the
     * rendered string, template segments included. An article under a
     * category named for its keyword genuinely has that keyword in its
     * <title>, and reporting otherwise would send the editor chasing a
     * problem that does not exist.
     *
     * @param  array<string, mixed>  $seo
     * @param  array{title: string, description: string}  $resolved
     * @return list<Check>
     */
    private function focusKeywordChecks(Page $page, array $seo, string $locale, array $resolved): array
    {
        $keyword = LocalizedSeo::value($seo['focus_keyword'] ?? null, $locale);

        if ($keyword === '') {
            return [$this->check('focus_keyword', 'warn', __('tbtop-cms::pages.seo_checklist.focus_keyword.empty'))];
        }

        $heading = (string) $page->getTranslation('heading', $locale);
        $slug = (string) $page->pathFor($locale);

        return [
            $this->keywordPresence('focus_keyword_in_title', $keyword, $resolved['title']),
            $this->keywordPresence('focus_keyword_in_description', $keyword, $resolved['description']),
            $this->keywordPresence('focus_keyword_in_heading', $keyword, $heading),
            $this->keywordPresence('focus_keyword_in_slug', $keyword, $slug),
        ];
    }

    /** @return Check */
    private function keywordPresence(string $key, string $keyword, string $haystack): array
    {
        $found = $haystack !== '' && mb_stripos($haystack, $keyword) !== false;

        return $found
            ? $this->check($key, 'pass', __('tbtop-cms::pages.seo_checklist.' . $key . '.pass'))
            : $this->check($key, 'fail', __('tbtop-cms::pages.seo_checklist.' . $key . '.fail'));
    }

    /**
     * Compares the RENDERED title and description, not each page's base: a
     * template is free to drop {title}/{description} entirely (e.g. plain
     * "{site_name}"), which collapses distinct bases into one identical
     * value — the exact collision these checks exist to report.
     *
     * Still avoids a full resolve() per page, which would also build every
     * page's alternates and JSON-LD objects. renderedTitle()/
     * renderedDescription() take the breadcrumbs lazily, so the per-page
     * ancestor query only runs for templates that actually reference
     * {parent}.
     *
     * One cursor pass covers both checks rather than two: they compare
     * against the same set of other pages, so a second full table scan would
     * only double the query cost for no extra information.
     *
     * Only the columns both cascades read are hydrated, and drafts count: a
     * duplicate that is about to be published is exactly what this warns
     * about.
     *
     * @return list<Check>
     */
    private function duplicateChecks(Page $page, string $title, string $description, string $locale): array
    {
        // An empty value needs no scan: there is nothing to collide with, and
        // the presence checks above already flag it.
        $checkTitle = $title !== '';
        $checkDescription = $description !== '';
        $titleDuplicate = false;
        $descriptionDuplicate = false;

        if ($checkTitle || $checkDescription) {
            $others = Page::query()
                ->whereKeyNot($page->getKey())
                ->select(['id', 'type', 'subtype', 'parent_id', 'status', 'published_at', 'unpublish_at', 'seo', 'title', 'name', 'summary'])
                ->cursor();

            foreach ($others as $other) {
                if ($checkTitle && ! $titleDuplicate) {
                    $otherTitle = $this->resolver->renderedTitle(
                        $other,
                        $locale,
                        fn (): array => $this->breadcrumbs->ancestors($other, $locale),
                    );

                    $titleDuplicate = $otherTitle === $title;
                }

                if ($checkDescription && ! $descriptionDuplicate) {
                    $otherDescription = $this->resolver->renderedDescription(
                        $other,
                        $locale,
                        fn (): array => $this->breadcrumbs->ancestors($other, $locale),
                    );

                    $descriptionDuplicate = $otherDescription === $description;
                }

                if ((! $checkTitle || $titleDuplicate) && (! $checkDescription || $descriptionDuplicate)) {
                    break;
                }
            }
        }

        return [
            $titleDuplicate
                ? $this->check('duplicate_title', 'warn', __('tbtop-cms::pages.seo_checklist.duplicate_title.warn'))
                : $this->check('duplicate_title', 'pass', __('tbtop-cms::pages.seo_checklist.duplicate_title.pass')),
            $descriptionDuplicate
                ? $this->check('duplicate_description', 'warn', __('tbtop-cms::pages.seo_checklist.duplicate_description.warn'))
                : $this->check('duplicate_description', 'pass', __('tbtop-cms::pages.seo_checklist.duplicate_description.pass')),
        ];
    }

    /**
     * Checks whose failure changes how the page appears in a result list, as
     * opposed to those that improve a page already fit to publish.
     *
     * The split exists because a flat list of twelve equal rows makes "no
     * description at all" read with the same weight as "the keyword is missing
     * from the slug", and an editor triaging a page needs to know which one to
     * fix first. Statamic's model: a required check that fails is a failure, an
     * optional one is a suggestion. Deliberately not a score — see the note on
     * scoring in the SEO gap analysis.
     */
    private const array REQUIRED_KEYS = ['title', 'description', 'indexable', 'canonical', 'duplicate_title', 'duplicate_description'];

    /**
     * @param  'pass'|'warn'|'fail'  $status
     * @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string, required: bool}
     */
    private function check(string $key, string $status, string $detail): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'label' => __('tbtop-cms::pages.seo_checklist.labels.' . $key),
            'detail' => $detail,
            'required' => in_array($key, self::REQUIRED_KEYS, true),
        ];
    }
}
