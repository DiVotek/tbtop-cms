<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Illuminate\Validation\ValidationException;
use LogicException;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Refs\PageTypeFilter;

/**
 * The page `$s->pages()->creatable()` makes from a name typed into the
 * select: published, of the field's one of() type, under the parent of the
 * newest page of that type (a new tag lands next to the others, under
 * `/tags`), with slugs derived from the name like any new page. The first
 * page of the type is made in Pages: until then the select refuses. A name
 * a page of that type already has in the locale (any case) picks that page
 * instead: typing "japan" twice makes one tag.
 */
final class PageQuickCreate
{
    /** @return array{value: string, label: string} */
    public static function create(PageTypeFilter $filter, string $name, string $locale): array
    {
        [$type, $subtype] = self::pair($filter);
        // The first one is made in Pages: only then is it known where the rest go.
        $sibling = Page::query()->where('type', $type)->where('subtype', $subtype)->latest('id')->first();

        if ($sibling === null) {
            throw ValidationException::withMessages(['name' => __('tbtop-cms::pages.refs.create_needs_parent')]);
        }
        $existing = self::sameName($type, $subtype, trim($name), $locale);
        if ($existing !== null) {
            return $existing;
        }
        $parentId = $sibling->parent_id;

        $page = new Page;
        $page->fill([
            'type' => $type,
            'subtype' => $subtype,
            'parent_id' => $parentId,
            'status' => PageStatus::Published,
            'is_indexable' => true,
            'sort_order' => 0,
        ]);
        $page->setTranslation('name', $locale, trim($name));

        $starter = app(StarterBlocks::class)->contentFor($page);
        $page->content = $starter !== [] ? $starter : app(TypeTemplateContent::class)->contentFor($page);
        $page->save();

        return ['value' => (string) $page->getKey(), 'label' => trim($name)];
    }

    /** @return array{value: string, label: string}|null The newest page of the type named $name in $locale, ignoring case. */
    private static function sameName(string $type, ?string $subtype, string $name, string $locale): ?array
    {
        $wanted = mb_strtolower($name);
        $pages = Page::query()->where('type', $type)->where('subtype', $subtype)->latest('id')->get();

        foreach ($pages as $page) {
            $own = $page->getTranslation('name', $locale, false);
            if (is_string($own) && mb_strtolower(trim($own)) === $wanted) {
                return ['value' => (string) $page->getKey(), 'label' => $own];
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string|null} */
    private static function pair(PageTypeFilter $filter): array
    {
        if (count($filter->pairs) !== 1) {
            throw new LogicException('pages()->creatable() needs exactly one of() type to create.');
        }

        $parts = explode('/', $filter->pairs[0], 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
