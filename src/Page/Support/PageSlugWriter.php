<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Illuminate\Support\Str;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;

class PageSlugWriter
{
    public function __construct(private readonly Languages $languages) {}

    public function sync(Page $page): void
    {
        $changed = false;

        foreach ($this->locales() as $locale) {
            if ($this->writeRow($page, $locale)) {
                $changed = true;
            }
        }

        if ($changed) {
            $this->cascadeChildren($page);
        }
    }

    public function backfillLocale(string $locale): void
    {
        foreach (Page::query()->whereNull('parent_id')->get() as $root) {
            $this->writeDown($root, $locale);
        }
    }

    public function remove(Page $page): void
    {
        PageSlug::query()->where('page_id', $page->getKey())->delete();
    }

    private function writeDown(Page $page, string $locale): void
    {
        $this->writeRow($page, $locale);

        foreach (Page::query()->where('parent_id', $page->getKey())->get() as $child) {
            $this->writeDown($child, $locale);
        }
    }

    private function cascadeChildren(Page $page): void
    {
        foreach (Page::query()->where('parent_id', $page->getKey())->get() as $child) {
            $this->sync($child);
        }
    }

    private function writeRow(Page $page, string $locale): bool
    {
        $existing = PageSlug::query()
            ->where('page_id', $page->getKey())
            ->where('locale', $locale)
            ->first();

        $base = $existing !== null ? $existing->slug : $this->slugBase($page, $locale);
        [$slug, $path] = $this->resolve($page, $locale, $base);

        if ($existing !== null && $existing->slug === $slug && $existing->path === $path) {
            return false;
        }

        PageSlug::query()->updateOrCreate(
            ['page_id' => $page->getKey(), 'locale' => $locale],
            ['slug' => $slug, 'path' => $path],
        );

        return true;
    }

    /** @return array{0: string, 1: string} */
    private function resolve(Page $page, string $locale, string $base): array
    {
        $parentPath = $this->parentPath($page, $locale);
        $slug = $base;
        $suffix = 2;

        while (true) {
            $path = $this->join($parentPath, $slug);

            $taken = PageSlug::query()
                ->where('locale', $locale)
                ->where('path', $path)
                ->where('page_id', '!=', $page->getKey())
                ->exists();

            if (! $taken) {
                return [$slug, $path];
            }

            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    }

    private function slugBase(Page $page, string $locale): string
    {
        if ($page->is_system && $page->parent_id === null) {
            return '';
        }

        $name = (string) ($page->getTranslation('name', $locale, false)
            ?: $page->getTranslation('name', $this->languages->defaultLocale(), false));
        $slug = Str::slug($name);

        if ($slug === '') {
            return 'page-' . $page->getKey();
        }

        return rtrim(mb_substr($slug, 0, 120), '-');
    }

    private function parentPath(Page $page, string $locale): string
    {
        if ($page->parent_id === null) {
            return '';
        }

        return (string) PageSlug::query()
            ->where('page_id', $page->parent_id)
            ->where('locale', $locale)
            ->value('path');
    }

    private function join(string $parentPath, string $slug): string
    {
        if ($parentPath === '') {
            return $slug;
        }

        if ($slug === '') {
            return $parentPath;
        }

        return $parentPath . '/' . $slug;
    }

    /** @return list<string> */
    private function locales(): array
    {
        return array_map(
            static fn (array $row): string => $row['locale'],
            $this->languages->frontendActive(),
        );
    }
}
