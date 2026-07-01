<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tbtop\Cms\Cms;
use Tbtop\Cms\CmsServiceProvider;

// Smoke route proving the package provider boots inside the workbench host.
Route::get('/', static fn () => response()->json([
    'package' => 'tbtop-cms',
    'booted' => array_key_exists(
        CmsServiceProvider::class,
        app()->getLoadedProviders(),
    ),
]));

// Serve the CMS frontend from the workbench host. Skipped under tests, which
// register the routes themselves so each case controls its own fixtures.
if (! app()->runningUnitTests()) {
    app(Cms::class)->frontendRoutes();
}
