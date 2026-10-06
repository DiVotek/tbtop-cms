<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Support\Facades\Validator;
use Tbtop\Cms\Page\Models\Page;

/**
 * Named rule `page_ref:<type or type/subtype>,…` on each id a pages field
 * posts: the page exists (not trashed) and matches the field's of() filter.
 * Drafts pass — an editor may reference a page before publishing it.
 */
final class PageRefRule
{
    public const RULE = 'page_ref';

    public static function register(): void
    {
        Validator::extend(
            self::RULE,
            static fn (string $attribute, mixed $value, array $parameters): bool => self::passes($value, PageTypeFilter::of($parameters)),
        );

        Validator::replacer(
            self::RULE,
            static fn (): string => (string) __('tbtop-cms::pages.refs.invalid'),
        );
    }

    public static function forFilter(PageTypeFilter $filter): string
    {
        return $filter->pairs === [] ? self::RULE : self::RULE . ':' . implode(',', $filter->pairs);
    }

    public static function passes(mixed $value, PageTypeFilter $filter): bool
    {
        $id = PageIds::one($value);

        return $id !== null && $filter->apply(Page::query()->whereKey($id))->exists();
    }
}
