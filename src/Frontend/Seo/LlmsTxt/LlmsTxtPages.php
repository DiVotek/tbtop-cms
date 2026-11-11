<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Tbtop\Cms\Frontend\Markdown\MarkdownPages;
use Tbtop\Cms\Frontend\Seo\PageOwnSeo;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageTranslation;

/**
 * One llms.txt section per base page type: the live, indexable pages of
 * that type, by the rules the sitemap uses (live scope, `is_indexable`, no
 * error pages).
 *
 * Articles are split by their parent category instead: one section per
 * category, titled with its name and in the order of the category list,
 * then the generic article section for those without a category parent. A
 * repair shop's services and its blog posts are different things to an
 * agent, and the category is where the editor already told them apart.
 * The category itself only names the section: its articles list by their
 * own rules even while it is unpublished or noindex.
 *
 * Links point at the page's `.md` while markdown versions are on.
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
     * slug path, not a column, so the order cannot be taken from SQL. On a
     * type with more pages, the ones past the first 1000 by sort order are
     * not considered; a type that large is better listed by a host section.
     */
    private const int TREE_CANDIDATES = 1000;

    public function __construct(
        private PageTypeRegistry $types,
        private MarkdownPages $pages,
        private LlmsTxtSettings $settings,
        private Languages $languages,
    ) {}

    /** @return list<array{title: string, links: list<LlmsTxtLink>}> */
    public function sections(string $locale): array
    {
        $sections = [];

        foreach ($this->listedTypes() as $type) {
            if ($type->key() === 'article') {
                array_push($sections, ...$this->articleSections($locale, self::title($type, $locale)));

                continue;
            }

            $links = $this->links($this->treeOrder($type->key(), $locale), $locale);

            if ($links !== []) {
                $sections[] = ['title' => self::title($type, $locale), 'links' => $links];
            }
        }

        return $sections;
    }

    /** @return list<array{title: string, links: list<LlmsTxtLink>}> */
    private function articleSections(string $locale, string $genericTitle): array
    {
        $limit = $this->settings->perType();
        $categories = $this->articleCategories($locale);
        $fallback = $this->languages->defaultLocale();
        $sections = [];

        foreach ($categories as $category) {
            $links = $this->links($this->newestFirst($this->query('article', $locale)->where('parent_id', $category->id), $limit), $locale);

            if ($links !== []) {
                $title = PageTranslation::text($category, 'name', $locale, $fallback) ?? $genericTitle;
                $sections[] = ['title' => $title, 'links' => $links];
            }
        }

        $categoryIds = array_map(static fn (Page $category): int => $category->id, $categories);
        $uncategorised = $this->query('article', $locale)->where(
            static fn (Builder $query): Builder => $query->whereNull('parent_id')->orWhereNotIn('parent_id', $categoryIds),
        );
        $links = $this->links($this->newestFirst($uncategorised, $limit), $locale);

        if ($links !== []) {
            $sections[] = ['title' => $genericTitle, 'links' => $links];
        }

        return $sections;
    }

    /**
     * The category pages (any subtype of the base) that are parents of listed
     * articles, in the category list's order. A category without a slug in
     * the locale has no depth to sort by and goes last.
     *
     * @return list<Page>
     */
    private function articleCategories(string $locale): array
    {
        $parentIds = $this->query('article', $locale)->whereNotNull('parent_id')->distinct()->pluck('parent_id')->all();

        if ($parentIds === []) {
            return [];
        }

        $categories = Page::query()->whereIn('id', $parentIds)->where('type', 'category')->with('slugs')->get()->all();

        usort($categories, static fn (Page $a, Page $b): int => self::treeKey($a, $locale) <=> self::treeKey($b, $locale));

        return $categories;
    }

    /** @return array{int, int, int, int} Home first, then depth, sort order and id. */
    private static function treeKey(Page $page, string $locale): array
    {
        $path = $page->pathFor($locale);

        if ($path === null) {
            return [2, 0, $page->sort_order, $page->id];
        }

        $path = trim($path, '/');

        return [$path === '' ? 0 : 1, substr_count($path, '/'), $page->sort_order, $page->id];
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

    /**
     * @param  iterable<Page>  $pages
     * @return list<LlmsTxtLink>
     */
    private function links(iterable $pages, string $locale): array
    {
        $limit = $this->settings->perType();
        $links = [];

        foreach ($pages as $page) {
            $seo = PageOwnSeo::blob($page);
            $links[] = new LlmsTxtLink(
                PageOwnSeo::title($page, $seo, $locale),
                (string) $this->pages->linkFor($page, $locale),
                PageOwnSeo::description($page, $seo, $locale),
            );

            if (count($links) === $limit) {
                break;
            }
        }

        return $links;
    }

    /**
     * @param  Builder<Page>  $query
     * @return Collection<int, Page>
     */
    private function newestFirst(Builder $query, int $limit): Collection
    {
        return $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return list<Page> */
    private function treeOrder(string $type, string $locale): array
    {
        $pages = $this->query($type, $locale)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::TREE_CANDIDATES)
            ->get()
            ->all();

        usort($pages, static fn (Page $a, Page $b): int => self::treeKey($a, $locale) <=> self::treeKey($b, $locale));

        return $pages;
    }

    /**
     * Only pages with a slug in the locale: one without it has no URL to
     * list and must not take a place under the limits.
     *
     * @return Builder<Page>
     */
    private function query(string $type, string $locale): Builder
    {
        return Page::query()
            ->live()
            ->where('type', $type)
            ->where('is_indexable', true)
            ->whereNull('error_class')
            ->whereHas('slugs', static fn (Builder $slugs): Builder => $slugs->where('locale', $locale))
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
