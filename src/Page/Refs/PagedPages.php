<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Tbtop\Cms\Frontend\Support\CurrentPage;
use Tbtop\Cms\Page\Models\Page;

/**
 * One page of a live-pages list for an archive (a tag's articles, a
 * category's children): newest first by published_at, then id. The page
 * number comes from the request's `?page=` unless given. The listing is
 * recorded on CurrentPage, so the page being rendered gets a `?page=N`
 * canonical and a page past the end is a 404 (PageController).
 */
final readonly class PagedPages
{
    public const string QUERY_KEY = 'page';

    public function __construct(private CurrentPage $current) {}

    /**
     * @param  Builder<Page>  $query  Live pages, unordered.
     * @return array{pages: list<Page>, pagination: array{page: int, pages: int, total: int, perPage: int}}
     */
    public function take(Builder $query, int $perPage, ?int $pageNumber = null): array
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException('perPage needs at least 1.');
        }

        $page = max(1, $pageNumber ?? self::requested());
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $perPage));

        $rows = $query->with('slugs')
            // NULL last on every driver: a page published before the first-publish stamp has none.
            ->orderByRaw('published_at IS NULL')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $this->current->paginate($page, $pages);

        return [
            'pages' => array_values($rows->all()),
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'perPage' => $perPage],
        ];
    }

    /** `?page=` of the current request when it is a positive integer, else 1. */
    public static function requested(): int
    {
        $raw = request()->query(self::QUERY_KEY);

        return is_string($raw) && preg_match('/^[1-9][0-9]{0,8}$/', $raw) === 1 ? (int) $raw : 1;
    }
}
