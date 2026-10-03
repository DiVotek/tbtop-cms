<?php

declare(strict_types=1);

namespace Tbtop\Cms\SharedBlock;

use Tbtop\Cms\Page\Models\Page;

/**
 * Which pages reference each shared block. Read from every page's envelope
 * rather than a join table: the pointer lives inside the JSON content, and a
 * page save is the only thing that changes it.
 */
final class SharedBlockUsage
{
    /** @return array<int, list<Page>> pages by shared block id, in page sort order */
    public static function index(): array
    {
        $index = [];

        foreach (Page::query()->orderBy('sort_order')->orderBy('id')->get() as $page) {
            foreach (self::refsIn($page->content) as $ref) {
                $index[$ref][] = $page;
            }
        }

        return $index;
    }

    /** @return list<Page> */
    public static function pagesUsing(int $id): array
    {
        return self::index()[$id] ?? [];
    }

    /** @return list<int> distinct shared ids a page's content points at */
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
