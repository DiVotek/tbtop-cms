<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * A leaf's type follows its place in the tree: under a category it is an
 * article, at the root a page (the stock hierarchy already forbids a page
 * under a category and an article without one). The forms apply that rule
 * instead of bouncing the save off PageHierarchy:
 *
 * - type kept, parent changed: an editor who gives a page a category, or
 *   takes an article's away, gets the other leaf type (the edit form asks
 *   to confirm first, see flip());
 * - type picked by hand: a page drops any parent (a stale picker value from
 *   before the type changed), an article must name its category.
 *
 * Both article rules hold only while the article type requiresParent(); a
 * host ArticleType that drops it keeps root articles as articles.
 *
 * Only a category parent flips a page; a page under a host container that
 * allows pages stays a page. Categories and host types are left to
 * PageHierarchy as before.
 */
final class PageLeafType
{
    public const string PAGE = 'page';

    public const string ARTICLE = 'article';

    /** @return array{type: string, parentId: ?int} */
    public static function resolve(Page $page, string $type, ?int $parentId): array
    {
        $isTypeKept = $page->exists && $page->type === $type;

        if ($isTypeKept && $type === self::PAGE && $parentId !== null) {
            // Only a category turns its page into an article; a host container
            // that allows pages keeps them (PageHierarchy judges the rest).
            return self::isCategory($parentId)
                ? ['type' => self::ARTICLE, 'parentId' => $parentId]
                : ['type' => self::PAGE, 'parentId' => $parentId];
        }
        $isOrphanArticle = $type === self::ARTICLE && $parentId === null && self::articleRequiresParent();
        if ($isTypeKept && $isOrphanArticle) {
            return ['type' => self::PAGE, 'parentId' => null];
        }
        if ($type === self::PAGE) {
            return ['type' => self::PAGE, 'parentId' => null];
        }
        if ($isOrphanArticle) {
            throw ValidationException::withMessages([
                'parent_id' => __('tbtop-cms::pages.validation.article_needs_category'),
            ]);
        }

        return ['type' => $type, 'parentId' => $parentId];
    }

    /**
     * The edit form's condition under which saving turns the loaded page into
     * the other leaf type — implicitly by its category (resolve()) or by an
     * explicit pick in the type select — with the confirm copy. Null for
     * anything that is not a page or an article.
     *
     * @return array{when: Cond, title: string, description: string}|null
     */
    public static function flip(Page $page): ?array
    {
        return match ($page->type) {
            self::PAGE => [
                // A page already under a host container keeps that parent
                // unchanged; only a new parent (a category) flips it. A legacy
                // page already under a category flips on any save (resolve()).
                'when' => Cond::any(
                    Cond::eq('type', self::ARTICLE),
                    $page->parent_id === null || self::isCategory($page->parent_id)
                        ? Cond::all(Cond::eq('type', self::PAGE), Cond::notEmpty('parent_id'))
                        : Cond::all(Cond::eq('type', self::PAGE), Cond::notEmpty('parent_id'), Cond::neq('parent_id', (string) $page->parent_id)),
                ),
                'title' => __('tbtop-cms::pages.leaf_flip.to_article_title'),
                'description' => __('tbtop-cms::pages.leaf_flip.to_article'),
            ],
            self::ARTICLE => [
                'when' => self::articleRequiresParent()
                    ? Cond::any(Cond::eq('type', self::PAGE), Cond::all(Cond::eq('type', self::ARTICLE), Cond::empty('parent_id')))
                    : Cond::eq('type', self::PAGE),
                'title' => __('tbtop-cms::pages.leaf_flip.to_page_title'),
                'description' => __('tbtop-cms::pages.leaf_flip.to_page'),
            ],
            default => null,
        };
    }

    public static function articleRequiresParent(): bool
    {
        return app(PageTypeRegistry::class)->get(self::ARTICLE)->requiresParent();
    }

    private static function isCategory(int $parentId): bool
    {
        return Page::query()->whereKey($parentId)->value('type') === 'category';
    }

    /**
     * The subtype an article gets from its category (PageTypeInterface::
     * childSubtype()) when it arrives there without one: on create, or when a
     * page becomes an article. An existing article keeps what it has.
     */
    public static function defaultSubtype(Page $page, string $type, ?int $parentId, mixed $subtype): ?string
    {
        $isArriving = ! $page->exists || $page->type !== self::ARTICLE;
        if ($type !== self::ARTICLE || ! $isArriving || $parentId === null || (is_string($subtype) && $subtype !== '')) {
            return null;
        }

        $parent = Page::query()->find($parentId);
        if ($parent === null) {
            return null;
        }

        $registry = app(PageTypeRegistry::class);
        $declared = null;
        foreach ($registry->forPage($parent) as $parentType) {
            $declared = $parentType->childSubtype() ?? $declared;
        }

        $subtypes = array_map(static fn (PageTypeInterface $t): string => $t->key(), $registry->subtypesOf(self::ARTICLE));

        return in_array($declared, $subtypes, true) ? $declared : null;
    }
}
