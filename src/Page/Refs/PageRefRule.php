<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Support\Facades\Validator;
use Tbtop\Cms\Page\Models\Page;

/**
 * Named rule `page_ref:<type or type/subtype>,…` on each id a pages field
 * posts: the page exists and matches the field's of() filter. Drafts pass —
 * an editor may reference a page before publishing it. Trashed pages pass
 * too: trashing a target must not lock every page that references it out of
 * saving, and the site already drops non-live pages from the list. An
 * `except=<id>` parameter rejects the edited page itself, matching the search.
 */
final class PageRefRule
{
    public const RULE = 'page_ref';

    private const string EXCEPT = 'except=';

    public static function register(): void
    {
        Validator::extend(
            self::RULE,
            static function (string $attribute, mixed $value, array $parameters): bool {
                $except = null;
                $pairs = [];
                foreach ($parameters as $parameter) {
                    if (str_starts_with($parameter, self::EXCEPT)) {
                        $except = PageIds::one(substr($parameter, strlen(self::EXCEPT)));
                    } else {
                        $pairs[] = $parameter;
                    }
                }

                return self::passes($value, PageTypeFilter::of($pairs), $except);
            },
        );

        Validator::replacer(
            self::RULE,
            static fn (): string => (string) __('tbtop-cms::pages.refs.invalid'),
        );
    }

    public static function forFilter(PageTypeFilter $filter, ?int $exceptPageId = null): string
    {
        $parameters = [...$filter->pairs, ...($exceptPageId === null ? [] : [self::EXCEPT . $exceptPageId])];

        return $parameters === [] ? self::RULE : self::RULE . ':' . implode(',', $parameters);
    }

    public static function passes(mixed $value, PageTypeFilter $filter, ?int $exceptPageId = null): bool
    {
        $id = PageIds::one($value);

        return $id !== null && $id !== $exceptPageId && $filter->apply(Page::withTrashed()->whereKey($id))->exists();
    }
}
