<?php

use Illuminate\Support\Facades\Route;
use Tbtop\Cms\CmsServiceProvider;

// Smoke route proving the package provider boots inside the workbench host.
Route::get('/', static fn () => response()->json([
    'package' => 'tbtop-cms',
    'booted' => array_key_exists(
        CmsServiceProvider::class,
        app()->getLoadedProviders(),
    ),
]));
