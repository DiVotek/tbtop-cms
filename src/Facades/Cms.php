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
