<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend;

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

    /** @return list<array{label: string, path: string, url: string}> */
    public function forPage(Page $page, string $locale): array
    {
        return $this->crumbs(AncestorChain::forPage($page), $locale);
    }

    /**
     * The trail as BreadcrumbList describes it: the site root is the first
     * item for every page, whether or not an editor wired it as the parent.
     *
     * Deliberately NOT folded into forPage(): that array is also the source
     * of the `{parent}` SEO template variable (SeoResolver::parentLabel()
     * reads its last element) and of the host-facing `breadcrumbs` prop.
     * Prepending home there would turn `{parent}` from empty into "Home" on
     * every top-level page, rewriting live titles.
     *
     * @return list<array{label: string, path: string, url: string}>
     */
    public function trailForSchema(Page $page, string $locale): array
    {
        $ancestors = AncestorChain::forPage($page);
        $home = $this->home();

        if ($home !== null && $home->getKey() !== $page->getKey()
            && ! $this->contains($ancestors, $home)) {
            array_unshift($ancestors, $home);
        }

        return $this->crumbs($ancestors, $locale);
    }

    /**
     * @param  list<Page>  $ancestors
     * @return list<array{label: string, path: string, url: string}>
     */
    private function crumbs(array $ancestors, string $locale): array
    {
        if ($ancestors === []) {
            return [];
        }

        $paths = $this->ancestorPaths($ancestors, $locale);
        $crumbs = [];

        foreach ($ancestors as $ancestor) {
            $path = (string) ($paths[$ancestor->getKey()] ?? '');
            $crumbs[] = [
                'label' => $this->label($ancestor, $locale),
                'path' => $path,
                'url' => $this->url->build($path, $locale),
            ];
        }

        return $crumbs;
    }

    /**
     * The seeded site root (PageSeeder). Nothing at the schema level
     * guarantees one exists — a host that never ran the installer has none —
     * so this is nullable by contract, not merely defensively. Memoized
     * including the miss: a SeoAudit sweep asks once per page.
     */
    private function home(): ?Page
    {
        if (! $this->homeLoaded) {
            $this->homeLoaded = true;
            $this->home = Page::query()
                ->where('is_system', true)
                ->whereNull('parent_id')
                ->first();
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
     * @param  list<Page>  $ancestors
     * @return array<int, string>
     */
    private function ancestorPaths(array $ancestors, string $locale): array
    {
        $ids = array_map(static fn (Page $page): int => $page->getKey(), $ancestors);

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
