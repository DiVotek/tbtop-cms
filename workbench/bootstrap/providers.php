<?php

use Inertia\ServiceProvider as InertiaServiceProvider;
use Tbtop\Admin\AdminServiceProvider;
use Tbtop\Cms\CmsServiceProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

return [
    InertiaServiceProvider::class,
    AdminServiceProvider::class,
    CmsServiceProvider::class,
    WorkbenchServiceProvider::class,
];
