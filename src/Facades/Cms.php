<?php

declare(strict_types=1);

namespace Tbtop\Cms\Facades;

use Illuminate\Support\Facades\Facade;
use Tbtop\Cms\Cms as CmsManager;

/**
 * @method static void frontendRoutes()
 * @method static \Tbtop\Cms\Page\Models\Page|null resolve(string $path, ?string $locale = null)
 * @method static string url(\Tbtop\Cms\Page\Models\Page $page, ?string $locale = null)
 * @method static list<array{label: string, path: string, url: string}> breadcrumbs(\Tbtop\Cms\Page\Models\Page $page, ?string $locale = null)
 * @method static \Tbtop\Cms\Form\Forms forms()
 * @method static list<\Tbtop\Cms\Page\Models\Page> referencedBy(\Tbtop\Cms\Page\Models\Page|null $page, string $field, ?string $from = null, ?string $locale = null)
 * @method static list<array{id: string, name: string, url: string}> referencedByLinks(\Tbtop\Cms\Page\Models\Page|null $page, string $field, ?string $from = null, ?string $locale = null)
 * @method static array{pages: list<\Tbtop\Cms\Page\Models\Page>, pagination: array{page: int, pages: int, total: int, perPage: int}} referencedByPaged(\Tbtop\Cms\Page\Models\Page|null $page, string $field, ?string $from = null, int $perPage = 20, ?int $pageNumber = null)
 * @method static array{items: list<array{id: string, name: string, url: string}>, pagination: array{page: int, pages: int, total: int, perPage: int}} referencedByLinksPaged(\Tbtop\Cms\Page\Models\Page|null $page, string $field, ?string $from = null, int $perPage = 20, ?int $pageNumber = null, ?string $locale = null)
 * @method static array{pages: list<\Tbtop\Cms\Page\Models\Page>, pagination: array{page: int, pages: int, total: int, perPage: int}} childrenPaged(\Tbtop\Cms\Page\Models\Page|null $page, ?string $from = null, int $perPage = 20, ?int $pageNumber = null)
 * @method static array{items: list<array{id: string, name: string, url: string}>, pagination: array{page: int, pages: int, total: int, perPage: int}} childrenLinksPaged(\Tbtop\Cms\Page\Models\Page|null $page, ?string $from = null, int $perPage = 20, ?int $pageNumber = null, ?string $locale = null)
 * @method static \Tbtop\Cms\Collection\EntryList collection(string $key, ?string $locale = null)
 *
 * @see CmsManager
 */
class Cms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CmsManager::class;
    }
}
