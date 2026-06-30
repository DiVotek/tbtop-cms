<?php

declare(strict_types=1);

namespace Tbtop\Cms;

use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tbtop\Cms\Admin\CmsPanel;
use Tbtop\Cms\Admin\Commands\CreateAdminCommand;
use Tbtop\Cms\Admin\Commands\GrantAdminCommand;
use Tbtop\Cms\Admin\Commands\RevokeAdminCommand;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Console\InstallCommand;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageSlugWriter;
use Tbtop\Cms\Redirect\Observers\PageSlugRedirects;
use Tbtop\Cms\Redirect\RedirectResolver;
use Tbtop\Cms\Settings\Settings;

class CmsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tbtop-cms')
            ->hasConfigFile()
            ->hasMigration('create_languages_table')
            ->hasMigration('create_pages_table')
            ->hasMigration('create_page_slugs_table')
            ->hasMigration('create_cms_user_profiles_table')
            ->hasMigration('create_redirects_table')
            ->hasMigration('create_cms_settings_table')
            ->hasCommands([
                InstallCommand::class,
                CreateAdminCommand::class,
                GrantAdminCommand::class,
                RevokeAdminCommand::class,
            ])
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(Languages::class);
        $this->app->singleton(PageTypeRegistry::class);
        $this->app->singleton(BlockRegistry::class);
        $this->app->scoped(BlockContentResolver::class);
        $this->app->scoped(PageResolver::class);
        $this->app->scoped(RedirectResolver::class);
        $this->app->scoped(Settings::class);
        $this->app->scoped(Cms::class);
        $this->registerAdminPanel();
    }

    public function packageBooted(): void
    {
        $this->wireAdminLoginRedirect();
        $this->wireLocaleSlugBackfill();
        $this->wireRedirectMaintenance();
    }

    private function registerAdminPanel(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        /** @var list<class-string> $panels */
        $panels = (array) config('tbtop-admin.panels', []);

        if (! in_array(CmsPanel::class, $panels, true)) {
            $panels[] = CmsPanel::class;
            config(['tbtop-admin.panels' => $panels]);
        }
    }

    private function wireAdminLoginRedirect(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        if (config('tbtop-cms.admin.pages.login') === null) {
            return;
        }

        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        Route::redirect('login', $prefix . '/login')->name('login');
    }

    private function wireLocaleSlugBackfill(): void
    {
        Language::saved(function (Language $language): void {
            $activated = $language->wasRecentlyCreated
                ? $language->is_frontend_active
                : ($language->wasChanged('is_frontend_active') && $language->is_frontend_active);

            if (! $activated) {
                return;
            }

            app(Languages::class)->refresh();
            app(PageSlugWriter::class)->backfillLocale($language->locale);
        });
    }

    private function wireRedirectMaintenance(): void
    {
        PageSlug::observe(PageSlugRedirects::class);
    }
}
