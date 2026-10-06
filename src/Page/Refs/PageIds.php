<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

/** Reads a stored pages value — untrusted JSON — into page ids, dropping anything that is not one. */
final class PageIds
{
    public static function one(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value) || strlen($value) > 18) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /** @return list<int> in stored order, first occurrence kept */
    public static function list(mixed $value): array
    {
        $ids = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $id = self::one($item);
            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
