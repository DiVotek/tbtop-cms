<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Why a page's (type, subtype) pair breaks "`type` is a base, `subtype`
 * belongs to it". The one rule PageObserver enforces on a write and the
 * page_type_pairs doctor check reports on stored rows. An unregistered
 * `type` is not a pair problem: PageHierarchy rejects it on a write.
 */
enum PageTypePairProblem: string
{
    case TypeIsSubtype = 'type_is_subtype';
    case SubtypeInvalid = 'subtype_invalid';

    public static function of(string $type, ?string $subtype, PageTypeRegistry $registry): ?self
    {
        if ($registry->has($type) && $registry->get($type)->base() !== $type) {
            return self::TypeIsSubtype;
        }

        if ($subtype === null || $subtype === '') {
            return null;
        }

        $isSubtypeOfType = $subtype !== $type && $registry->has($subtype) && $registry->get($subtype)->base() === $type;

        return $isSubtypeOfType ? null : self::SubtypeInvalid;
    }
}
