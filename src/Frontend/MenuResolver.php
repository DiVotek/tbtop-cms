<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend;

use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Menu\Models\Menu;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;

/**
 * Resolves every stored menu into frontend-ready items for one locale:
 * labels picked per locale (override -> page-name translation -> default
 * locale), hrefs built from the page slug / raw url / anchor. Items whose
 * page is unpublished or deleted are dropped.
 */
class MenuResolver
{
    public function __construct(
        private readonly PageUrl $url,
        private readonly Languages $languages,
    ) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function forLocale(string $locale): array
    {
        $menus = Menu::query()->get();

        if ($menus->isEmpty()) {
            return [];
        }

        $ids = $this->pageIds($menus->pluck('items')->all());
        $pages = $ids === [] ? [] : Page::query()
            ->whereIn('id', $ids)
            ->where('status', PageStatus::Published->value)
            ->get()
            ->keyBy(static fn (Page $page): int => (int) $page->getKey())
            ->all();
        $default = $this->languages->defaultLocale();
        $paths = $this->slugPaths($ids, $locale, $default);

        $result = [];
        foreach ($menus as $menu) {
            $result[$menu->key] = $this->items($menu->items ?? [], $locale, $default, $pages, $paths);
        }

        return $result;
    }

    /**
     * @param  list<mixed>  $items
     * @param  array<int, Page>  $pages
     * @param  array<int, array<string, string>>  $paths
     * @return list<array<string, mixed>>
     */
    private function items(array $items, string $locale, string $default, array $pages, array $paths): array
    {
        $resolved = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $entry = $this->resolveItem($item, $locale, $default, $pages, $paths);
            if ($entry === null) {
                continue;
            }

            if (isset($item['children']) && is_array($item['children'])) {
                $children = $this->items($item['children'], $locale, $default, $pages, $paths);
                if ($children !== []) {
                    $entry['children'] = $children;
                }
            }

            $resolved[] = $entry;
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, Page>  $pages
     * @param  array<int, array<string, string>>  $paths
     * @return array<string, mixed>|null
     */
    private function resolveItem(array $item, string $locale, string $default, array $pages, array $paths): ?array
    {
        $type = $item['type'] ?? null;

        if ($type === 'page' || $type === 'category') {
            $id = (int) ($item[$type === 'page' ? 'page_id' : 'category_id'] ?? 0);
            $page = $pages[$id] ?? null;
            if (! $page instanceof Page) {
                return null;
            }
            $label = $this->pageLabel($item['label'] ?? null, $page, $locale, $default);
            $href = $this->pageUrl($paths[$id] ?? [], $locale, $default);
        } elseif ($type === 'url' || $type === 'anchor') {
            $target = $this->pick($item[$type] ?? null, $locale, $default);
            if ($target === null) {
                return null;
            }
            $label = $this->pick($item['label'] ?? null, $locale, $default) ?? '';
            $href = $type === 'anchor' && ! str_starts_with($target, '#') ? '#' . $target : $target;
        } else {
            return null;
        }

        $entry = ['type' => $type, 'label' => $label, 'url' => $href];
        if (! empty($item['target_blank'])) {
            $entry['target_blank'] = true;
        }

        return $entry;
    }

    /**
     * Per locale, override first, then the page-name translation; the default
     * locale repeats the same pair as the last resort.
     */
    private function pageLabel(mixed $override, Page $page, string $locale, string $default): string
    {
        foreach (array_unique([$locale, $default]) as $loc) {
            $value = $this->overrideFor($override, $loc, $default);
            if ($value !== null) {
                return $value;
            }
            $name = trim((string) $page->getTranslation('name', $loc, false));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * A slug row may be missing for a non-default locale (page saved before
     * the locale existed); fall back to the default-locale URL, which always
     * resolves, instead of fabricating a localized path that 404s.
     *
     * @param  array<string, string>  $byLocale
     */
    private function pageUrl(array $byLocale, string $locale, string $default): string
    {
        if (isset($byLocale[$locale])) {
            return $this->url->build($byLocale[$locale], $locale);
        }

        return $this->url->build($byLocale[$default] ?? '', $default);
    }

    /** The requested locale's value with default-locale fallback; a legacy scalar serves every locale. */
    private function pick(mixed $value, string $locale, string $default): ?string
    {
        if (is_string($value)) {
            return trim($value) !== '' ? $value : null;
        }
        if (! is_array($value)) {
            return null;
        }

        foreach (array_unique([$locale, $default]) as $loc) {
            $candidate = $value[$loc] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /** A single locale's stored override; a legacy scalar counts as the default locale's. */
    private function overrideFor(mixed $value, string $locale, string $default): ?string
    {
        if (is_string($value)) {
            return $locale === $default && trim($value) !== '' ? $value : null;
        }
        $candidate = is_array($value) ? ($value[$locale] ?? null) : null;

        return is_string($candidate) && trim($candidate) !== '' ? $candidate : null;
    }

    /**
     * @param  list<mixed>  $itemSets
     * @return list<int>
     */
    private function pageIds(array $itemSets): array
    {
        $ids = [];
        foreach ($itemSets as $items) {
            $this->collectIds($items, $ids);
        }

        return array_values(array_unique($ids));
    }

    /** @param  list<int>  $ids */
    private function collectIds(mixed $items, array &$ids): void
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['page_id', 'category_id'] as $field) {
                $id = (int) ($item[$field] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $this->collectIds($item['children'] ?? null, $ids);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, string>>
     */
    private function slugPaths(array $ids, string $locale, string $default): array
    {
        if ($ids === []) {
            return [];
        }

        $paths = [];
        $rows = PageSlug::query()
            ->whereIn('page_id', $ids)
            ->whereIn('locale', array_unique([$locale, $default]))
            ->get(['page_id', 'locale', 'path']);
        foreach ($rows as $row) {
            $paths[(int) $row->page_id][(string) $row->locale] = (string) $row->path;
        }

        return $paths;
    }
}
