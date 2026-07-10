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

    /**
     * @param  array<string, string>  $slugOverrides  Locale => author-chosen slug base
     *                                                (e.g. from the create form's slug
     *                                                field). Only consulted when a
     *                                                locale has no existing PageSlug row
     *                                                yet; an empty/missing override for a
     *                                                locale falls back to generating the
     *                                                base from the name, same as before.
     */
    public function sync(Page $page, array $slugOverrides = []): void
    {
        $changed = false;

        foreach ($this->locales() as $locale) {
            if ($this->writeRow($page, $locale, $slugOverrides[$locale] ?? '')) {
                $changed = true;
            }
        }

        if ($changed) {
            $this->cascadeChildren($page);
        }

        $page->unsetRelation('slugs');
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
        $this->writeRow($page, $locale, '');

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

    private function writeRow(Page $page, string $locale, string $slugOverride): bool
    {
        $existing = PageSlug::query()
            ->where('page_id', $page->getKey())
            ->where('locale', $locale)
            ->first();

        $base = $existing !== null ? $existing->slug : $this->slugBaseFor($page, $locale, $slugOverride);
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

    /**
     * An explicit author-chosen slug (from the create form) wins over the
     * name-derived default; an empty override falls back to slugBase()'s
     * existing name-generation behavior.
     */
    private function slugBaseFor(Page $page, string $locale, string $slugOverride): string
    {
        if ($slugOverride !== '') {
            $slug = Str::slug($slugOverride);

            if ($slug !== '') {
                return rtrim(mb_substr($slug, 0, 120), '-');
            }
        }

        return $this->slugBase($page, $locale);
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
