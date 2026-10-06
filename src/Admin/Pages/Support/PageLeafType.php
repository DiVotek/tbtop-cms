<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Cms\Page\Models\Page;

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
 * Categories and host types are left to PageHierarchy as before.
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
            return ['type' => self::ARTICLE, 'parentId' => $parentId];
        }
        if ($isTypeKept && $type === self::ARTICLE && $parentId === null) {
            return ['type' => self::PAGE, 'parentId' => null];
        }
        if ($type === self::PAGE) {
            return ['type' => self::PAGE, 'parentId' => null];
        }
        if ($type === self::ARTICLE && $parentId === null) {
            throw ValidationException::withMessages([
                'parent_id' => __('tbtop-cms::pages.validation.article_needs_category'),
            ]);
        }

        return ['type' => $type, 'parentId' => $parentId];
    }

    /**
     * The edit form's condition under which saving changes the leaf type —
     * the mirror of resolve() for the page as loaded — with the confirm copy.
     * Null for anything that is not a page or an article.
     *
     * @return array{when: Cond, title: string, description: string}|null
     */
    public static function flip(Page $page): ?array
    {
        $pageWithParent = Cond::all(Cond::eq('type', self::PAGE), Cond::notEmpty('parent_id'));

        return match ($page->type) {
            self::PAGE => [
                'when' => $pageWithParent,
                'title' => __('tbtop-cms::pages.leaf_flip.to_article_title'),
                'description' => __('tbtop-cms::pages.leaf_flip.to_article'),
            ],
            self::ARTICLE => [
                'when' => Cond::any(Cond::all(Cond::eq('type', self::ARTICLE), Cond::empty('parent_id')), $pageWithParent),
                'title' => __('tbtop-cms::pages.leaf_flip.to_page_title'),
                'description' => __('tbtop-cms::pages.leaf_flip.to_page'),
            ],
            default => null,
        };
    }
}
