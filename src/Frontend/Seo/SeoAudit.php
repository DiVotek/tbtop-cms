<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Illuminate\Support\Collection;
use Tbtop\Cms\Block\MediaUrlResolver;
use Tbtop\Cms\Frontend\Breadcrumbs;
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
 * same cost SeoChecklist::duplicateChecks() avoids by re-deriving just the
 * title/description cascades instead of calling resolve(). This class
 * re-derives the few cascades it needs (own title, own description, own
 * og:image) the same way, straight from one Page query's already-hydrated
 * columns.
 *
 * @phpstan-type Issue array{severity: 'warn'|'fail', key: string, issue: string, pageId: int, page: string, locale: string, detail: string, sortKey: string}
 */
final class SeoAudit
{
    public function __construct(
        private readonly Languages $languages,
        private readonly Settings $settings,
        private readonly MediaUrlResolver $mediaUrlResolver,
        private readonly SeoResolver $resolver,
        private readonly Breadcrumbs $breadcrumbs,
    ) {}

    /** @return list<Issue> */
    public function run(): array
    {
        $pages = $this->pages();
        $locales = $this->localeCodes();

        $issues = [];

        array_push($issues, ...$this->missingTranslationAndTitleAndDescription($pages, $locales));
        array_push($issues, ...$this->duplicateTitlesAndDescriptions($pages, $locales));
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
     * Mirrors SeoChecklist::duplicateChecks(): compares rendered titles and
     * rendered descriptions, because a template that omits {title}/
     * {description} turns distinct bases into one identical value.
     * Title/description-only rendering with lazy breadcrumbs keeps this off
     * the per-page ancestor query unless {parent} is in play.
     *
     * One pass over $pages builds both groupings (by title, by description)
     * rather than two — the report already holds every page in memory, so a
     * second full loop plus a second call to the resolver would only double
     * the rendering cost for no extra information.
     *
     * @param  Collection<int, Page>  $pages
     * @param  list<string>  $locales
     * @return list<Issue>
     */
    private function duplicateTitlesAndDescriptions($pages, array $locales): array
    {
        $issues = [];

        foreach ($locales as $locale) {
            $byTitle = [];
            $byDescription = [];

            foreach ($pages as $page) {
                $breadcrumbs = fn (): array => $this->breadcrumbs->ancestors($page, $locale);

                $title = $this->resolver->renderedTitle($page, $locale, $breadcrumbs);

                if ($title !== '') {
                    $byTitle[$title][] = $page;
                }

                $description = $this->resolver->renderedDescription($page, $locale, $breadcrumbs);

                if ($description !== '') {
                    $byDescription[$description][] = $page;
                }
            }

            array_push($issues, ...$this->duplicateGroupIssues($byTitle, $locale, 'duplicate_title'));
            array_push($issues, ...$this->duplicateGroupIssues($byDescription, $locale, 'duplicate_description'));
        }

        return $issues;
    }

    /**
     * @param  array<string, list<Page>>  $groups  Rendered value => pages sharing it.
     * @return list<Issue>
     */
    private function duplicateGroupIssues(array $groups, string $locale, string $issueKey): array
    {
        $issues = [];

        foreach ($groups as $group) {
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
                    $issueKey,
                    (int) $page->getKey(),
                    $this->label($page, $locale),
                    $locale,
                    __('tbtop-cms::seo_report.issues.' . $issueKey . '.detail', [
                        'pages' => implode(', ', $otherLabels),
                    ]),
                );
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

        $issues = [];

        foreach ($pages as $page) {
            $seo = PageOwnSeo::blob($page);
            $override = $seo['og_image'] ?? null;

            // A page that named an image has made its choice, and a broken
            // id is still that choice — ogImageDetails() publishes nothing
            // rather than the site default, so folding the default in here
            // would report a page as fine while its card renders blank.
            $image = PageOwnSeo::namesImage($page, $seo)
                ? (is_string($override) && $override !== '' ? $override : $page->image)
                : $globalDefault;
            $url = is_string($image) && $image !== '' ? $this->mediaUrlResolver->resolve($image) : null;

            if (trim((string) $url) === '') {
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
        // Error pages are resolved by error_class, never served at a URL —
        // same exclusion as the sitemap.
        return Page::query()
            ->live()
            ->whereNull('error_class')
            ->select(['id', 'type', 'subtype', 'parent_id', 'status', 'published_at', 'unpublish_at', 'name', 'title', 'summary', 'seo', 'image', 'is_indexable'])
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

    /** @param  array<string, mixed>  $seo */
    private function ownTitle(Page $page, array $seo, string $locale): string
    {
        return PageOwnSeo::title($page, $seo, $locale);
    }

    /** @param  array<string, mixed>  $seo */
    private function ownDescription(Page $page, array $seo, string $locale): string
    {
        return PageOwnSeo::description($page, $seo, $locale);
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
            'key' => $issueKey,
            'issue' => __('tbtop-cms::seo_report.issues.' . $issueKey . '.label'),
            'pageId' => $pageId,
            'page' => $page,
            'locale' => $locale,
            'detail' => $detail,
            'sortKey' => $severityRank . '-' . $page . '-' . $issueKey . '-' . $locale,
        ];
    }
}
