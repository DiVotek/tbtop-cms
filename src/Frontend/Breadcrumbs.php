<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend;

use Tbtop\Cms\Frontend\Support\HomePage;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\PageUrl;
use Tbtop\Cms\Page\Support\AncestorChain;
use Tbtop\Cms\Page\Support\PageTranslation;

class Breadcrumbs
{
    private bool $homeLoaded = false;

    private ?int $homeId = null;

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
     * Only live ancestors and a live site root make the trail: a draft or
     * expired one has no page a visitor could follow the crumb to. The page
     * itself always stays (a preview shows what a visitor will see once it
     * goes live), and the fewer-than-two rule applies after the filter.
     *
     * @return list<array{label: string, path: string, url: string}>
     */
    public function forPage(Page $page, string $locale): array
    {
        $chain = $this->liveAncestors($page);
        $home = $this->liveHome();

        if ($home !== null && $home->getKey() !== $page->getKey()
            && ! $this->contains($chain, $home)) {
            array_unshift($chain, $home);
        }

        $chain[] = $page;

        return count($chain) < 2 ? [] : $this->crumbs($chain, $locale);
    }

    /**
     * Real live ancestry only — no site root, no self. The `{parent}` SEO
     * template variable reads its last element (SeoTextRenderer::parentLabel()),
     * so it names the nearest live ancestor; feeding it forPage() would turn
     * `{parent}` into "Home" on top-level pages and into the page's own
     * label everywhere else.
     *
     * @return list<array{label: string, path: string, url: string}>
     */
    public function ancestors(Page $page, string $locale): array
    {
        return $this->crumbs($this->liveAncestors($page), $locale);
    }

    /**
     * Filtered here, not in AncestorChain: hierarchy checks and starter
     * blocks need the full chain, draft ancestors included.
     *
     * @return list<Page>
     */
    private function liveAncestors(Page $page): array
    {
        return array_values(array_filter(
            AncestorChain::forPage($page),
            static fn (Page $ancestor): bool => $ancestor->isLive(),
        ));
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

    /**
     * Only the root's id is memoized (including the miss: a SeoAudit sweep
     * asks once per page); its liveness and labels are read fresh, because
     * this instance outlives a request — the route caches the controller
     * that holds it.
     */
    private function liveHome(): ?Page
    {
        if (! $this->homeLoaded) {
            $this->homeLoaded = true;
            $this->homeId = HomePage::find()?->getKey();
        }

        return $this->homeId === null ? null : Page::query()->live()->find($this->homeId);
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

    // name before title: editors write SEO-length phrases into title (it feeds
    // <title>), and Google may show BreadcrumbList in place of the URL.
    private function label(Page $page, string $locale): string
    {
        return PageTranslation::firstText($page, ['name', 'title'], $locale, $this->languages->defaultLocale());
    }
}
