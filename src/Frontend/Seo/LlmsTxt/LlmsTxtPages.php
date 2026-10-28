<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\URL;
use Tbtop\Cms\Frontend\Seo\PageOwnSeo;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\PageUrl;

/**
 * One llms.txt section per base page type: the live, indexable pages of
 * that type, by the rules the sitemap uses (live scope, `is_indexable`, no
 * error pages).
 *
 * Articles are newest first, every other type follows the tree: the home
 * page, then shallower before deeper, then the editor's sort order. Link
 * text and notes are the page's own title and description, without the
 * site-wide templates — a template appending the site name to every line
 * says nothing an agent needs.
 */
final readonly class LlmsTxtPages
{
    /**
     * Rows read per tree-ordered type before sorting: depth comes from the
     * slug path, not a column, so the order cannot be taken from SQL.
     */
    private const int TREE_CANDIDATES = 1000;

    public function __construct(
        private PageTypeRegistry $types,
        private PageUrl $url,
        private LlmsTxtSettings $settings,
    ) {}

    /** @return list<array{title: string, links: list<LlmsTxtLink>}> */
    public function sections(string $locale): array
    {
        $sections = [];

        foreach ($this->listedTypes() as $type) {
            $links = $this->links($type->key(), $locale);

            if ($links !== []) {
                $sections[] = ['title' => self::title($type, $locale), 'links' => $links];
            }
        }

        return $sections;
    }

    /** @return list<PageTypeInterface> Base types in registry order, narrowed by the editor's choice. */
    private function listedTypes(): array
    {
        $chosen = $this->settings->types();

        return array_values(array_filter(
            $this->types->all(),
            static fn (PageTypeInterface $type): bool => $type->base() === $type->key()
                && ($chosen === null || in_array($type->key(), $chosen, true)),
        ));
    }

    /** @return list<LlmsTxtLink> */
    private function links(string $type, string $locale): array
    {
        $limit = $this->settings->perType();
        $pages = $type === 'article' ? $this->newestFirst($type, $limit) : $this->treeOrder($type, $locale);

        $links = [];

        foreach ($pages as $page) {
            $path = $page->pathFor($locale);

            if ($path === null) {
                continue;
            }

            $seo = PageOwnSeo::blob($page);
            $links[] = new LlmsTxtLink(
                PageOwnSeo::title($page, $seo, $locale),
                URL::to($this->url->build($path, $locale)),
                PageOwnSeo::description($page, $seo, $locale),
            );

            if (count($links) === $limit) {
                break;
            }
        }

        return $links;
    }

    /** @return Collection<int, Page> */
    private function newestFirst(string $type, int $limit): Collection
    {
        // Twice the limit: a page with no slug in this locale is skipped.
        return $this->query($type)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit * 2)
            ->get();
    }

    /** @return list<Page> */
    private function treeOrder(string $type, string $locale): array
    {
        $pages = $this->query($type)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::TREE_CANDIDATES)
            ->get()
            ->all();

        $key = static function (Page $page) use ($locale): array {
            $path = trim((string) $page->pathFor($locale), '/');

            return [$path === '' ? 0 : 1, substr_count($path, '/'), $page->sort_order];
        };

        usort($pages, static fn (Page $a, Page $b): int => $key($a) <=> $key($b));

        return $pages;
    }

    /** @return Builder<Page> */
    private function query(string $type): Builder
    {
        return Page::query()
            ->live()
            ->where('type', $type)
            ->where('is_indexable', true)
            ->whereNull('error_class')
            ->with('slugs');
    }

    /** The plural section name for a built-in type, else the type's own label. */
    private static function title(PageTypeInterface $type, string $locale): string
    {
        $key = 'tbtop-cms::llms.sections.' . $type->key();
        $label = __($key, [], $locale);

        return $label === $key ? $type->label() : $label;
    }
}
