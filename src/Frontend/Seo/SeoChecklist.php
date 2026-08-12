<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/**
 * Deterministic per-page SEO checks rendered as a read-only section above the
 * page editor's SEO fields (PageForm::seoFields()). Every check is a plain
 * fact — no scoring heuristic, no readability analysis. Pixel-width preview
 * bars are a separate, later pass (see the checklist's section docblock in
 * PageForm) and deliberately not part of this.
 *
 * Reuses SeoResolver::resolve() rather than re-deriving the title/description/
 * canonical/og_image cascades a second time — SeoResolver is the single
 * source of truth for those, and its internals are private by design.
 */
class SeoChecklist
{
    private const int TITLE_MAX = 60;

    private const int DESCRIPTION_MIN = 50;

    private const int DESCRIPTION_MAX = 160;

    public function __construct(
        private readonly SeoResolver $resolver,
        private readonly Languages $languages,
    ) {}

    /** @return list<array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string}> */
    public function for(Page $page, string $locale): array
    {
        $resolved = $this->resolver->resolve($page, $locale);
        $seo = is_array($page->seo) ? $page->seo : [];

        return [
            $this->titleCheck($resolved['title']),
            $this->descriptionCheck($resolved['description']),
            $this->ogImageCheck($resolved['ogImage']),
            $this->indexableCheck($page),
            $this->canonicalCheck($resolved['canonical']),
            $this->hreflangCheck($page),
            ...$this->focusKeywordChecks($page, $seo, $locale, $resolved),
            $this->duplicateTitleCheck($page, $resolved['title'], $locale),
        ];
    }

    /** @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string} */
    private function titleCheck(string $title): array
    {
        $length = mb_strlen($title);

        if ($title === '') {
            return $this->check('title', 'fail', __('tbtop-cms::pages.seo_checklist.title.fail'));
        }

        if ($length > self::TITLE_MAX) {
            return $this->check('title', 'warn', __('tbtop-cms::pages.seo_checklist.title.warn', ['length' => $length]));
        }

        return $this->check('title', 'pass', __('tbtop-cms::pages.seo_checklist.title.pass', ['length' => $length]));
    }

    /** @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string} */
    private function descriptionCheck(string $description): array
    {
        $length = mb_strlen($description);

        if ($description === '') {
            return $this->check('description', 'fail', __('tbtop-cms::pages.seo_checklist.description.fail'));
        }

        if ($length < self::DESCRIPTION_MIN || $length > self::DESCRIPTION_MAX) {
            return $this->check('description', 'warn', __('tbtop-cms::pages.seo_checklist.description.warn', ['length' => $length]));
        }

        return $this->check('description', 'pass', __('tbtop-cms::pages.seo_checklist.description.pass', ['length' => $length]));
    }

    /** @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string} */
    private function ogImageCheck(?string $ogImage): array
    {
        return $ogImage === null
            ? $this->check('og_image', 'warn', __('tbtop-cms::pages.seo_checklist.og_image.warn'))
            : $this->check('og_image', 'pass', __('tbtop-cms::pages.seo_checklist.og_image.pass'));
    }

    /**
     * A published page with noindex set is a legitimate configuration (a
     * thank-you page, a duplicate of another canonical page, …) — surfaced
     * as a warn, not a fail, purely so it isn't invisible.
     *
     * @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string}
     */
    private function indexableCheck(Page $page): array
    {
        if (! $page->is_indexable && $page->status === PageStatus::Published) {
            return $this->check('indexable', 'warn', __('tbtop-cms::pages.seo_checklist.indexable.warn'));
        }

        return $this->check('indexable', 'pass', __('tbtop-cms::pages.seo_checklist.indexable.pass'));
    }

    /** @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string} */
    private function canonicalCheck(string $canonical): array
    {
        $canonicalHost = parse_url($canonical, PHP_URL_HOST);
        $siteHost = parse_url(url('/'), PHP_URL_HOST);

        if (is_string($canonicalHost) && is_string($siteHost) && $canonicalHost !== '' && $canonicalHost !== $siteHost) {
            return $this->check('canonical', 'warn', __('tbtop-cms::pages.seo_checklist.canonical.warn', ['host' => $canonicalHost]));
        }

        return $this->check('canonical', 'pass', __('tbtop-cms::pages.seo_checklist.canonical.pass'));
    }

    /**
     * Warns when the page has a slug in some, but not all, frontend-active
     * locales — a page fully absent from a locale is a normal, deliberate
     * choice (see SeoResolver::alternates()), so only the partial case is
     * worth flagging.
     *
     * @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string}
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
     * @param  array<string, mixed>  $seo
     * @param  array{title: string, description: string}  $resolved
     * @return list<array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string}>
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

    /** @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string} */
    private function keywordPresence(string $key, string $keyword, string $haystack): array
    {
        $found = $haystack !== '' && mb_stripos($haystack, $keyword) !== false;

        return $found
            ? $this->check($key, 'pass', __('tbtop-cms::pages.seo_checklist.' . $key . '.pass'))
            : $this->check($key, 'fail', __('tbtop-cms::pages.seo_checklist.' . $key . '.fail'));
    }

    /**
     * Compares against the same seo.title → title → name cascade
     * SeoResolver::metaTitle() applies, deliberately stopping there: running
     * a full resolve() per page would also build every page's alternates and
     * JSON-LD objects, turning one editor load into hundreds of resolutions
     * on a site of any size. The template/suffix step is skipped too — it is
     * site-wide, so it can never turn two distinct bases into a collision or
     * split a real one.
     *
     * Only the columns the cascade reads are hydrated, and drafts count:
     * a duplicate that is about to be published is exactly what this warns
     * about.
     *
     * @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string}
     */
    private function duplicateTitleCheck(Page $page, string $title, string $locale): array
    {
        if ($title === '') {
            return $this->check('duplicate_title', 'pass', __('tbtop-cms::pages.seo_checklist.duplicate_title.pass'));
        }

        $base = $this->metaTitleBase($page, $locale);

        if ($base === '') {
            return $this->check('duplicate_title', 'pass', __('tbtop-cms::pages.seo_checklist.duplicate_title.pass'));
        }

        $others = Page::query()
            ->whereKeyNot($page->getKey())
            ->select(['id', 'seo', 'title', 'name'])
            ->cursor();

        foreach ($others as $other) {
            if ($this->metaTitleBase($other, $locale) === $base) {
                return $this->check('duplicate_title', 'warn', __('tbtop-cms::pages.seo_checklist.duplicate_title.warn'));
            }
        }

        return $this->check('duplicate_title', 'pass', __('tbtop-cms::pages.seo_checklist.duplicate_title.pass'));
    }

    /** Mirrors SeoResolver::metaTitle()'s cascade, which is private there. */
    private function metaTitleBase(Page $page, string $locale): string
    {
        $seo = is_array($page->seo) ? $page->seo : [];

        return LocalizedSeo::value($seo['title'] ?? null, $locale)
            ?: (string) $page->getTranslation('title', $locale)
            ?: (string) $page->getTranslation('name', $locale);
    }

    /**
     * @param  'pass'|'warn'|'fail'  $status
     * @return array{key: string, status: 'pass'|'warn'|'fail', label: string, detail: string}
     */
    private function check(string $key, string $status, string $detail): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'label' => __('tbtop-cms::pages.seo_checklist.labels.' . $key),
            'detail' => $detail,
        ];
    }
}
