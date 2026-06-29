<?php

declare(strict_types=1);

namespace Tbtop\Cms;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tbtop\Cms\Language\Languages;

class CmsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tbtop-cms')
            ->hasConfigFile()
            ->hasMigration('create_languages_table')
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(Languages::class);
    }
}
