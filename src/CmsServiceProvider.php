<?php

declare(strict_types=1);

namespace Tbtop\Cms;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CmsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tbtop-cms')
            ->hasConfigFile();
    }
}
