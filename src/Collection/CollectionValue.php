<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection;

/**
 * The stored value of a block's `collection` field: `{mode, limit?, ids?}`.
 * Parsing never throws: an unknown or missing mode falls back to the first
 * mode the field allows, so a block added before the editor touched the
 * field still shows entries.
 */
final readonly class CollectionValue
{
    public const int DEFAULT_LIMIT = 3;

    /** @param  list<int>  $ids */
    public function __construct(
        public PickMode $mode,
        public int $limit,
        public array $ids,
    ) {}

    /** @param  list<PickMode>  $allowed  never empty */
    public static function from(mixed $value, array $allowed): self
    {
        $raw = is_array($value) ? $value : [];
        $mode = is_string($raw['mode'] ?? null) ? PickMode::tryFrom($raw['mode']) : null;
        if ($mode === null || ! in_array($mode, $allowed, true)) {
            $mode = $allowed[0];
        }

        $limit = $raw['limit'] ?? null;
        $limit = is_int($limit) || (is_string($limit) && ctype_digit($limit)) ? max(1, (int) $limit) : self::DEFAULT_LIMIT;

        return new self($mode, $limit, self::ids($raw['ids'] ?? null));
    }

    /** @return list<int> */
    public static function ids(mixed $raw): array
    {
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $id) {
            if ((is_int($id) && $id > 0) || (is_string($id) && ctype_digit($id) && $id !== '0')) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
