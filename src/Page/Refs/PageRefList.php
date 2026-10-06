<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Tbtop\Admin\I18n\TranslatableValue;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageUrl;

/**
 * Pages as the site sees them: `{id, name, url}` in one locale. A page with
 * no slug or no name for that locale is left out, like LinkResolver leaves
 * out a link to it.
 */
final class PageRefList
{
    // PageUrl, not Cms::url(): Cms reaches BlockContentResolver, which needs this class.
    public function __construct(private readonly PageUrl $pageUrl) {}

    /**
     * The live pages a stored pages value names, in stored order — one query
     * for the whole list; unknown, trashed and unpublished ids drop out.
     *
     * @return list<array{id: string, name: string, url: string}>
     */
    public function resolve(mixed $value, string $locale): array
    {
        $ids = PageIds::list($value);
        if ($ids === []) {
            return [];
        }

        $pages = Page::query()->live()->with('slugs')->whereKey($ids)->get()->keyBy('id');

        return $this->items(array_values(array_filter(array_map(
            static fn (int $id): ?Page => $pages->get($id),
            $ids,
        ))), $locale);
    }

    /**
     * @param  iterable<Page>  $pages
     * @return list<array{id: string, name: string, url: string}>
     */
    public function items(iterable $pages, string $locale): array
    {
        $items = [];
        foreach ($pages as $page) {
            $name = self::nameOf($page, $locale);
            $path = $page->pathFor($locale);
            if ($name === null || $path === null) {
                continue;
            }
            $items[] = ['id' => (string) $page->getKey(), 'name' => $name, 'url' => $this->pageUrl->build($path, $locale)];
        }

        return $items;
    }

    /** The name in $locale, else the default/first non-empty one; null when the page has none. */
    public static function nameOf(Page $page, string $locale): ?string
    {
        $raw = $page->getRawOriginal('name') ?? $page->name;
        $map = TranslatableValue::asLocaleMap($raw);
        $exact = is_array($map) ? ($map[$locale] ?? null) : null;

        if (is_string($exact) && trim($exact) !== '') {
            return $exact;
        }

        $picked = TranslatableValue::pick($raw);

        return is_string($picked) && trim($picked) !== '' ? $picked : null;
    }
}
