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
    public function __construct(
        private readonly PageUrl $url,
        private readonly Languages $languages,
    ) {}

    /** @return list<array{label: string, path: string, url: string}> */
    public function forPage(Page $page, string $locale): array
    {
        $ancestors = AncestorChain::forPage($page);

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
