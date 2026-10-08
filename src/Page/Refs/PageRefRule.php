<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Support\Facades\Validator;
use Tbtop\Cms\Page\Models\Page;

/**
 * Named rule `page_ref` on each id a pages field posts: the page exists.
 * The field's of() filter is applied by the search, not here: a target
 * retyped after it was picked must not lock every page that references it
 * out of saving, while the site keeps listing it. Drafts pass — an editor
 * may reference a page before publishing it. Trashed pages pass too, for the
 * same reason as a retyped one; the site drops non-live pages from the list.
 * An `except=<id>` parameter rejects the edited page itself, matching the search.
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
                foreach ($parameters as $parameter) {
                    if (str_starts_with($parameter, self::EXCEPT)) {
                        $except = PageIds::one(substr($parameter, strlen(self::EXCEPT)));
                    }
                }

                return self::passes($value, $except);
            },
        );

        Validator::replacer(
            self::RULE,
            static fn (): string => (string) __('tbtop-cms::pages.refs.invalid'),
        );
    }

    public static function except(?int $exceptPageId): string
    {
        return $exceptPageId === null ? self::RULE : self::RULE . ':' . self::EXCEPT . $exceptPageId;
    }

    public static function passes(mixed $value, ?int $exceptPageId = null): bool
    {
        $id = PageIds::one($value);

        return $id !== null && $id !== $exceptPageId && Page::withTrashed()->whereKey($id)->exists();
    }
}
