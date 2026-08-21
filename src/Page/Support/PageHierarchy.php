<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use DomainException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

class PageHierarchy
{
    public static function assert(Page $page, PageTypeRegistry $registry): void
    {
        self::lockHierarchyRows();

        self::assertTypeRegistered($page, $registry);
        self::assertChildrenAllowed($page, $registry);
        self::assertParentRequirement($page, $registry);

        if ($page->parent_id === null) {
            return;
        }

        self::assertParentAllowsChild($page, $registry);

        $chain = AncestorChain::forPage($page);
        self::assertNoCycle($page, $chain);
        self::assertNestingDepth($page, $chain, $registry);
    }

    private static function lockHierarchyRows(): void
    {
        // Structural writes are rare and cycles may involve disjoint changed
        // endpoints joined by unchanged ancestors. Locking the hierarchy in a
        // stable order serializes every parent/type mutation before validation.
        Page::withTrashed()->orderBy((new Page)->getKeyName())->lockForUpdate()->get();
    }

    private static function assertTypeRegistered(Page $page, PageTypeRegistry $registry): void
    {
        if (! $registry->has($page->type)) {
            throw new DomainException("Page type [{$page->type}] is not registered.");
        }
    }

    private static function assertChildrenAllowed(Page $page, PageTypeRegistry $registry): void
    {
        if (! $page->exists || ! $page->isDirty('type')) {
            return;
        }

        $allowed = $registry->get($page->type)->allowedChildren();

        foreach ($page->children()->distinct()->pluck('type')->all() as $childType) {
            if (! in_array($childType, $allowed, true)) {
                throw new DomainException("Page type [{$page->type}] cannot contain existing [{$childType}] child.");
            }
        }
    }

    private static function assertParentRequirement(Page $page, PageTypeRegistry $registry): void
    {
        if ($page->parent_id !== null) {
            return;
        }

        if ($registry->get($page->type)->requiresParent()) {
            throw new DomainException("Page type [{$page->type}] requires a parent.");
        }
    }

    private static function assertParentAllowsChild(Page $page, PageTypeRegistry $registry): void
    {
        if ($page->exists && $page->parent_id === $page->id) {
            throw new DomainException('A page cannot be its own parent.');
        }

        $parent = Page::withTrashed()->find($page->parent_id);

        if ($parent === null || $parent->trashed()) {
            throw new DomainException("Parent page [{$page->parent_id}] is missing or trashed.");
        }

        if (! in_array($page->type, $registry->get($parent->type)->allowedChildren(), true)) {
            throw new DomainException("Page type [{$parent->type}] cannot contain a [{$page->type}] child.");
        }
    }

    /** @param list<Page> $chain */
    private static function assertNoCycle(Page $page, array $chain): void
    {
        foreach ($chain as $ancestor) {
            if ($ancestor->id === $page->id) {
                throw new DomainException('Page parent change would create a cycle.');
            }
        }
    }

    /** @param list<Page> $chain */
    private static function assertNestingDepth(Page $page, array $chain, PageTypeRegistry $registry): void
    {
        $type = $registry->get($page->type);

        // Leaf types do not extend nesting; depth is bounded by their containers.
        if ($type->allowedChildren() === []) {
            return;
        }

        $depth = count($chain) + 1;

        if ($depth > $type->maxDepth()) {
            throw new DomainException("Nesting depth {$depth} exceeds maximum {$type->maxDepth()} for type [{$page->type}].");
        }
    }
}
