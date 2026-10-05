<?php

declare(strict_types=1);

namespace Tbtop\Cms\SharedBlock;

use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Page\Models\Page;

/**
 * Which pages and layout slots reference each shared block. Read from
 * every container's envelope rather than a join table: the pointer lives
 * inside the JSON content, and a page or layout save is the only thing
 * that changes it. Layouts count because the resolver honours a pointer
 * there too — a shared block a slot places must not look deletable.
 */
final class SharedBlockUsage
{
    /** @return array<int, list<Page|Layout>> containers by shared block id, pages in sort order then layouts */
    public static function index(): array
    {
        $index = [];

        foreach (Page::query()->orderBy('sort_order')->orderBy('id')->get() as $page) {
            foreach (self::refsIn($page->content) as $ref) {
                $index[$ref][] = $page;
            }
        }
        foreach (Layout::query()->orderBy('slot')->get() as $layout) {
            foreach (self::refsIn($layout->content) as $ref) {
                $index[$ref][] = $layout;
            }
        }

        return $index;
    }

    /**
     * Placement counts only, for screens that show a number and never the
     * containers (the page editor's add-block chooser, on every render):
     * reads the one `content` column instead of whole pages.
     *
     * @return array<int, int> container count by shared block id
     */
    public static function counts(): array
    {
        $counts = [];
        foreach ([Page::query(), Layout::query()] as $query) {
            foreach ($query->pluck('content') as $content) {
                foreach (self::refsIn($content) as $ref) {
                    $counts[$ref] = ($counts[$ref] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /** @return list<Page|Layout> */
    public static function using(int $id): array
    {
        return self::index()[$id] ?? [];
    }

    /** @return list<int> distinct shared ids a content list points at */
    public static function refsIn(mixed $content): array
    {
        $refs = [];
        foreach (is_array($content) ? $content : [] as $block) {
            $ref = SharedBlocks::refOf($block);
            if ($ref !== null && ! in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }
}
