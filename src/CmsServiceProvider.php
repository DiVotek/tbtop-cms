<?php

declare(strict_types=1);

namespace Tbtop\Cms;

use Illuminate\Auth\Events\Login;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tbtop\Cms\Admin\CmsPanel;
use Tbtop\Cms\Admin\Commands\CreateAdminCommand;
use Tbtop\Cms\Admin\Commands\GrantAdminCommand;
use Tbtop\Cms\Admin\Commands\RevokeAdminCommand;
use Tbtop\Cms\Admin\Http\Controllers\LogoutController;
use Tbtop\Cms\Admin\Listeners\SeedsLocaleFromProfile;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Cache\InvalidatesContentCache;
use Tbtop\Cms\Console\InstallCommand;
use Tbtop\Cms\Form\Events\SubmissionReceived;
use Tbtop\Cms\Form\Listeners\NotifiesAdminsOfSubmission;
use Tbtop\Cms\Form\SubmissionNotifier;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Language\Events\LanguagesChanged;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Events\LayoutSaved;
use Tbtop\Cms\Layout\LayoutResolver;
use Tbtop\Cms\Mail\CmsMailer;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Events\PagePublished;
use Tbtop\Cms\Page\Events\PageRestored;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Events\PageUnpublished;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\Observers\PageRevisions;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageSlugWriter;
use Tbtop\Cms\Redirect\Observers\PageSlugRedirects;
use Tbtop\Cms\Redirect\RedirectResolver;
use Tbtop\Cms\Settings\Events\SettingsSaved;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Telegram\TelegramClient;
use Tbtop\Cms\Telegram\TelegramInvites;
use Tbtop\Cms\Telegram\TelegramSubmissionNotifier;

class CmsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tbtop-cms')
            ->hasConfigFile()
            ->hasMigration('2026_01_01_000000_update_tables_add_tbtop_prefix_table')
            ->hasMigration('2026_01_01_000001_create_languages_table')
            ->hasMigration('2026_01_01_000002_create_pages_table')
            ->hasMigration('2026_01_01_000003_create_page_slugs_table')
            ->hasMigration('2026_01_01_000004_create_cms_user_profiles_table')
            ->hasMigration('2026_01_01_000005_create_redirects_table')
            ->hasMigration('2026_01_01_000006_create_cms_settings_table')
            ->hasMigration('2026_01_01_000007_create_cms_templates_table')
            ->hasMigration('2026_01_01_000008_create_cms_form_submissions_table')
            ->hasMigration('2026_01_01_000009_update_cms_form_submissions_add_lead_status_table')
            ->hasMigration('2026_01_01_000010_update_pages_add_body_table')
            ->hasMigration('2026_01_01_000011_create_cms_layouts_table')
            ->hasMigration('2026_01_01_000012_update_pages_add_image_table')
            ->hasMigration('2026_01_01_000014_update_user_id_columns_match_host_key_type')
            ->hasMigration('2026_01_01_000015_update_cms_user_profiles_add_user_id_foreign_table')
            ->hasMigration('2026_01_01_000016_update_cms_user_profiles_add_role_table')
            ->hasMigration('2026_01_01_000017_create_page_revisions_table')
            ->hasMigration('2026_01_01_000018_update_pages_add_scheduling_table')
            ->hasMigration('2026_01_01_000019_create_form_submission_comments_table')
            ->hasMigration('2026_01_01_000020_create_notifications_table')
            ->hasMigration('2026_08_11_000000_drop_cms_menus_table')
            ->hasMigration('2026_08_11_000001_update_cms_user_profiles_drop_role_table')
            ->hasMigration('2026_08_11_000002_update_cms_user_profiles_add_is_owner_table')
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
        $this->loadTranslations();
        $this->app->scoped(Languages::class);
        $this->app->singleton(PageTypeRegistry::class);
        $this->app->singleton(BlockRegistry::class);
        $this->app->scoped(BlockContentResolver::class);
        $this->app->scoped(LayoutResolver::class);
        $this->app->scoped(PageResolver::class);
        $this->app->scoped(RedirectResolver::class);
        $this->app->scoped(Settings::class);
        $this->app->scoped(CmsMailer::class);
        $this->app->scoped(Cms::class);
        $this->app->scoped(CmsCache::class);
        $this->app->scoped(TelegramClient::class);
        $this->app->scoped(TelegramInvites::class);
        $this->app->scoped(TelegramSubmissionNotifier::class);
        $this->app->scoped(SubmissionNotifier::class);
        $this->registerAdminPanel();
    }

    public function packageBooted(): void
    {
        $this->wireAdminLoginRedirect();
        $this->wireAdminLogout();
        $this->wireLocaleSlugBackfill();
        $this->wireRedirectMaintenance();
        $this->wirePageRevisions();
        $this->wireContentCacheInvalidation();
        $this->wireLocaleSeeding();
        $this->wireSubmissionNotifications();
        // The admin client reads/writes this cookie from JS; the profile
        // preferences form sets it server-side. Both must see plain text.
        EncryptCookies::except('tbtop_theme');
    }

    /**
     * Register package translations from a normalized path. Spatie's
     * hasTranslations() points the loader at `src/../resources/lang`; that
     * `/..` segment loads empty and poisons the translator's cache, so we
     * register the resolved path ourselves instead.
     */
    private function loadTranslations(): void
    {
        $path = realpath(__DIR__ . '/../resources/lang');
        if ($path !== false) {
            $this->loadTranslationsFrom($path, 'tbtop-cms');
        }
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

    /**
     * Server half of the admin client's logout contract: ProfileDropdown
     * (admin >=0.2.24) posts to `{prefix}/logout`. Registered by cms because
     * auth is cms-owned — the admin package ships no auth controllers.
     * Prefixed on purpose: a root `/logout` would occupy the host app's own
     * route namespace. Guarded by `auth:{guard}` only: logging out must not
     * require the CmsUserProfile row EnsureCmsAdmin checks for page access.
     */
    private function wireAdminLogout(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        Route::middleware(['web', 'auth:' . $guard])
            ->post($prefix . '/logout', LogoutController::class)
            ->name('tbtop.cms.logout');
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

    /**
     * A second Page observer, registered independently of PageObserver's
     * #[ObservedBy] attribute — see PageRevisions' class docblock for why.
     */
    private function wirePageRevisions(): void
    {
        Page::observe(PageRevisions::class);
    }

    /**
     * Every domain event that changes frontend-visible content bumps the
     * content cache version — see InvalidatesContentCache's docblock for why
     * one blanket bump instead of per-event targeted invalidation.
     * SubmissionReceived is intentionally excluded: submissions aren't content.
     */
    private function wireContentCacheInvalidation(): void
    {
        foreach ([
            PageSaved::class,
            PagePublished::class,
            PageUnpublished::class,
            PageDeleted::class,
            PageRestored::class,
            LayoutSaved::class,
            SettingsSaved::class,
            LanguagesChanged::class,
        ] as $event) {
            Event::listen($event, InvalidatesContentCache::class);
        }
    }

    /**
     * Seeds session('tbtop.locale') from the profile right after login —
     * see SeedsLocaleFromProfile's docblock for why this is needed.
     */
    private function wireLocaleSeeding(): void
    {
        Event::listen(Login::class, SeedsLocaleFromProfile::class);
    }

    /**
     * See NotifiesAdminsOfSubmission's docblock for why the actual fan-out
     * is deferred to app()->terminating() rather than run here.
     */
    private function wireSubmissionNotifications(): void
    {
        Event::listen(SubmissionReceived::class, NotifiesAdminsOfSubmission::class);
    }
}
