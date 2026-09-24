<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend;

use Tbtop\Cms\Frontend\Support\HomePage;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\PageUrl;
use Tbtop\Cms\Page\Support\AncestorChain;

class Breadcrumbs
{
    private bool $homeLoaded = false;

    private ?Page $home = null;

    public function __construct(
        private readonly PageUrl $url,
        private readonly Languages $languages,
    ) {}

    /**
     * The trail a visitor and BreadcrumbList both read: site root first,
     * whether or not an editor wired it as the parent (the seeded home is a
     * sibling of top-level pages, not their parent), then real ancestry,
     * then the page itself. Fewer than two crumbs is no trail — the home
     * page gets [] rather than a lone "Home".
     *
     * @return list<array{label: string, path: string, url: string}>
     */
    public function forPage(Page $page, string $locale): array
    {
        $chain = AncestorChain::forPage($page);
        $home = $this->home();

        if ($home !== null && $home->getKey() !== $page->getKey()
            && ! $this->contains($chain, $home)) {
            array_unshift($chain, $home);
        }

        $chain[] = $page;

        return count($chain) < 2 ? [] : $this->crumbs($chain, $locale);
    }

    /**
     * Real ancestry only — no site root, no self. The `{parent}` SEO
     * template variable reads its last element (SeoResolver::parentLabel());
     * feeding it forPage() would turn `{parent}` into "Home" on top-level
     * pages and into the page's own label everywhere else.
     *
     * @return list<array{label: string, path: string, url: string}>
     */
    public function ancestors(Page $page, string $locale): array
    {
        return $this->crumbs(AncestorChain::forPage($page), $locale);
    }

    /**
     * @param  list<Page>  $pages
     * @return list<array{label: string, path: string, url: string}>
     */
    private function crumbs(array $pages, string $locale): array
    {
        if ($pages === []) {
            return [];
        }

        $paths = $this->paths($pages, $locale);
        $crumbs = [];

        foreach ($pages as $crumbPage) {
            $path = (string) ($paths[$crumbPage->getKey()] ?? '');
            $crumbs[] = [
                'label' => $this->label($crumbPage, $locale),
                'path' => $path,
                'url' => $this->url->build($path, $locale),
            ];
        }

        return $crumbs;
    }

    /** Memoized including the miss: a SeoAudit sweep asks once per page. */
    private function home(): ?Page
    {
        if (! $this->homeLoaded) {
            $this->homeLoaded = true;
            $this->home = HomePage::find();
        }

        return $this->home;
    }

    /** @param list<Page> $ancestors */
    private function contains(array $ancestors, Page $home): bool
    {
        foreach ($ancestors as $ancestor) {
            if ($ancestor->getKey() === $home->getKey()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Page>  $pages
     * @return array<int, string>
     */
    private function paths(array $pages, string $locale): array
    {
        $ids = array_map(static fn (Page $page): int => $page->getKey(), $pages);

        return PageSlug::query()
            ->where('locale', $locale)
            ->whereIn('page_id', $ids)
            ->pluck('path', 'page_id')
            ->all();
    }

    private function label(Page $page, string $locale): string
    {
        $default = $this->languages->defaultLocale();

        foreach ([$locale, $default] as $loc) {
            foreach (['title', 'name'] as $key) {
                $value = (string) $page->getTranslation($key, $loc, false);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}
