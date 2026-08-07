<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tbtop\Cms\Frontend\PageUrl;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;

/**
 * @phpstan-type SitemapEntry array{loc: string, lastmod: string, alternates: array<string, string>}
 */
class SitemapEntryBuilder
{
    public function __construct(
        private readonly PageUrl $url,
        private readonly Languages $languages,
    ) {}

    /** @return list<SitemapEntry> */
    public function build(): array
    {
        $locales = $this->localeCodes();

        if ($locales === []) {
            return [];
        }

        $pages = Page::query()
            ->live()
            ->where('is_indexable', true)
            ->with('slugs')
            ->get();

        $entries = [];

        foreach ($pages as $page) {
            array_push($entries, ...$this->entriesForPage($page, $locales));
        }

        return $entries;
    }

    /**
     * @param  list<string>  $locales
     * @return list<SitemapEntry>
     */
    private function entriesForPage(Page $page, array $locales): array
    {
        $alternates = $this->alternates($page, $locales);

        if ($alternates === []) {
            return [];
        }

        $lastmod = $this->lastmod($page);

        return (new Collection($locales))
            ->filter(static fn (string $locale): bool => isset($alternates[$locale]))
            ->map(static fn (string $locale): array => [
                'loc' => $alternates[$locale],
                'lastmod' => $lastmod,
                'alternates' => $alternates,
            ])
            ->values()
            ->all();
    }

    private function lastmod(Page $page): string
    {
        $updatedAt = $page->getAttribute('updated_at');

        return $updatedAt instanceof Carbon ? $updatedAt->toAtomString() : '';
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function alternates(Page $page, array $locales): array
    {
        $alternates = [];

        foreach ($locales as $locale) {
            $path = $page->pathFor($locale);

            if ($path !== null) {
                $alternates[$locale] = url($this->url->build($path, $locale));
            }
        }

        return $alternates;
    }

    /** @return list<string> */
    private function localeCodes(): array
    {
        return array_map(
            static fn (array $row): string => $row['locale'],
            $this->languages->frontendActive(),
        );
    }
}
