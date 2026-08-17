<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Illuminate\Support\Collection;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

/**
 * Site-wide SEO audit for SeoReportPage — every published page, every check,
 * in one page load.
 *
 * Deliberately does NOT run SeoResolver::resolve() per page: that builds
 * alternates, JSON-LD objects, and every og:/twitter: field for a single
 * page's render. Multiplied by (pages × active locales) for a report this
 * would mean hundreds of resolutions per load on a site of any size — the
 * same cost SeoChecklist::duplicateTitleCheck() avoids by re-deriving just
 * the title cascade instead of calling resolve(). This class re-derives the
 * few cascades it needs (own title, own description, own og:image) the same
 * way, straight from one Page query's already-hydrated columns.
 *
 * @phpstan-type Issue array{severity: 'warn'|'fail', issue: string, pageId: int, page: string, locale: string, detail: string, sortKey: string}
 */
final class SeoAudit
{
    public function __construct(
        private readonly Languages $languages,
        private readonly Settings $settings,
    ) {}

    /** @return list<Issue> */
    public function run(): array
    {
        $pages = $this->pages();
        $locales = $this->localeCodes();

        $issues = [];

        array_push($issues, ...$this->missingTranslationAndTitleAndDescription($pages, $locales));
        array_push($issues, ...$this->duplicateTitles($pages, $locales));
        array_push($issues, ...$this->missingOgImages($pages));
        array_push($issues, ...$this->noindexed($pages));

        usort($issues, static fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey']);

        return $issues;
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @param  list<string>  $locales
     * @return list<Issue>
     */
    private function missingTranslationAndTitleAndDescription($pages, array $locales): array
    {
        $issues = [];

        foreach ($pages as $page) {
            $seo = is_array($page->seo) ? $page->seo : [];

            foreach ($locales as $locale) {
                $title = $this->ownTitle($page, $seo, $locale);
                $label = $this->label($page, $locale);

                if ($title === '') {
                    $issues[] = $this->issue(
                        'fail',
                        'missing_title',
                        (int) $page->getKey(),
                        $label,
                        $locale,
                        __('tbtop-cms::seo_report.issues.missing_title.detail'),
                    );
                }

                if ($this->ownDescription($page, $seo, $locale) === '') {
                    $issues[] = $this->issue(
                        'fail',
                        'missing_description',
                        (int) $page->getKey(),
                        $label,
                        $locale,
                        __('tbtop-cms::seo_report.issues.missing_description.detail'),
                    );
                }

                // Fallback disabled on BOTH reads here, unlike ownTitle()
                // above: metaTitle()'s fallback-enabled cascade answers "is
                // there a title to show?", which a fallback correctly
                // satisfies — but this check asks "does THIS locale have its
                // own words?", which a fallback value never answers, being by
                // definition someone else's locale's content.
                $hasOwnTitle = (string) $page->getTranslation('title', $locale, false) !== '';
                $hasOwnName = (string) $page->getTranslation('name', $locale, false) !== '';

                if (! $hasOwnTitle && ! $hasOwnName) {
                    $issues[] = $this->issue(
                        'warn',
                        'missing_translation',
                        (int) $page->getKey(),
                        $label,
                        $locale,
                        __('tbtop-cms::seo_report.issues.missing_translation.detail'),
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Mirrors SeoChecklist::duplicateTitleCheck()'s cascade and its choice to
     * stop before the site-wide template/suffix: the template can never turn
     * two distinct bases into a collision or split a real one, so applying it
     * here would cost N page loads worth of settings reads for no different
     * an answer.
     *
     * @param  Collection<int, Page>  $pages
     * @param  list<string>  $locales
     * @return list<Issue>
     */
    private function duplicateTitles($pages, array $locales): array
    {
        $issues = [];

        foreach ($locales as $locale) {
            $byTitle = [];

            foreach ($pages as $page) {
                $seo = is_array($page->seo) ? $page->seo : [];
                $title = $this->ownTitle($page, $seo, $locale);

                if ($title === '') {
                    continue;
                }

                $byTitle[$title][] = $page;
            }

            foreach ($byTitle as $title => $group) {
                if (count($group) < 2) {
                    continue;
                }

                foreach ($group as $page) {
                    $others = array_values(array_filter(
                        $group,
                        static fn (Page $other): bool => $other->getKey() !== $page->getKey(),
                    ));

                    $otherLabels = array_map(fn (Page $other): string => $this->label($other, $locale), $others);

                    $issues[] = $this->issue(
                        'warn',
                        'duplicate_title',
                        (int) $page->getKey(),
                        $this->label($page, $locale),
                        $locale,
                        __('tbtop-cms::seo_report.issues.duplicate_title.detail', [
                            'pages' => implode(', ', $otherLabels),
                        ]),
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Locale-independent: the og:image cascade (SeoResolver::ogImageDetails())
     * reads seo.og_image and page.image, neither of which is translatable,
     * before falling back to the site-wide default — so one row per page
     * covers every locale, same as the cascade itself.
     *
     * @param  Collection<int, Page>  $pages
     * @return list<Issue>
     */
    private function missingOgImages($pages): array
    {
        $globalDefault = $this->settings->get('seo.default_og_image');
        $hasGlobalDefault = is_string($globalDefault) && $globalDefault !== '';

        if ($hasGlobalDefault) {
            return [];
        }

        $issues = [];

        foreach ($pages as $page) {
            $seo = is_array($page->seo) ? $page->seo : [];
            $override = $seo['og_image'] ?? null;
            $hasOwnImage = (is_string($override) && $override !== '')
                || ($page->image !== null && $page->image !== '');

            if (! $hasOwnImage) {
                $issues[] = $this->issue(
                    'warn',
                    'missing_og_image',
                    (int) $page->getKey(),
                    $this->label($page, $this->languages->defaultLocale()),
                    '',
                    __('tbtop-cms::seo_report.issues.missing_og_image.detail'),
                );
            }
        }

        return $issues;
    }

    /**
     * Locale-independent: is_indexable is a plain column, not translatable.
     *
     * @param  Collection<int, Page>  $pages
     * @return list<Issue>
     */
    private function noindexed($pages): array
    {
        $issues = [];

        foreach ($pages as $page) {
            if (! $page->is_indexable) {
                $issues[] = $this->issue(
                    'warn',
                    'noindex',
                    (int) $page->getKey(),
                    $this->label($page, $this->languages->defaultLocale()),
                    '',
                    __('tbtop-cms::seo_report.issues.noindex.detail'),
                );
            }
        }

        return $issues;
    }

    /** @return Collection<int, Page> */
    private function pages()
    {
        return Page::query()
            ->live()
            ->select(['id', 'name', 'title', 'summary', 'seo', 'image', 'is_indexable'])
            ->get();
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
     * Mirrors SeoResolver::metaTitle()'s cascade — own values, no template.
     *
     * @param  array<string, mixed>  $seo
     */
    private function ownTitle(Page $page, array $seo, string $locale): string
    {
        return LocalizedSeo::value($seo['title'] ?? null, $locale)
            ?: (string) $page->getTranslation('title', $locale)
            ?: (string) $page->getTranslation('name', $locale);
    }

    /**
     * Mirrors SeoResolver::ownDescription()'s cascade — the description cascade minus its site-wide-filler last rung.
     *
     * @param  array<string, mixed>  $seo
     */
    private function ownDescription(Page $page, array $seo, string $locale): string
    {
        return LocalizedSeo::value($seo['description'] ?? null, $locale)
            ?: (string) $page->getTranslation('summary', $locale);
    }

    /** A human label for the row: the same title→name cascade the checklist uses, falling back to a placeholder for a page with neither in this locale. */
    private function label(Page $page, string $locale): string
    {
        $name = (string) $page->getTranslation('title', $locale)
            ?: (string) $page->getTranslation('name', $locale);

        return $name !== '' ? $name : __('tbtop-cms::seo_report.untitled_page', ['id' => $page->getKey()]);
    }

    /** @return Issue */
    private function issue(string $severity, string $issueKey, int $pageId, string $page, string $locale, string $detail): array
    {
        // fail sorts before warn ('0' < '1'); page label breaks ties so rows
        // for the same page stay together.
        $severityRank = $severity === 'fail' ? '0' : '1';

        return [
            'severity' => $severity,
            'issue' => __('tbtop-cms::seo_report.issues.' . $issueKey . '.label'),
            'pageId' => $pageId,
            'page' => $page,
            'locale' => $locale,
            'detail' => $detail,
            'sortKey' => $severityRank . '-' . $page . '-' . $issueKey . '-' . $locale,
        ];
    }
}
