<?php

declare(strict_types=1);

namespace Tbtop\Cms;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Inertia\Ssr\HttpGateway;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Commands\CreateAdminCommand;
use Tbtop\Cms\Admin\Commands\GrantAdminCommand;
use Tbtop\Cms\Admin\Commands\RevokeAdminCommand;
use Tbtop\Cms\Admin\Fields\Link;
use Tbtop\Cms\Admin\Fields\Pages;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Console\BlocksTypesCommand;
use Tbtop\Cms\Console\DoctorCommand;
use Tbtop\Cms\Console\InstallCommand;
use Tbtop\Cms\Form\SubmissionNotifier;
use Tbtop\Cms\Frontend\Errors\ErrorPageResolver;
use Tbtop\Cms\Frontend\Errors\ErrorResponse;
use Tbtop\Cms\Frontend\Http\Middleware\EnableSsr;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Frontend\Seo\RobotsRegistry;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeRegistry;
use Tbtop\Cms\Frontend\Support\CurrentPage;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Layout\LayoutResolver;
use Tbtop\Cms\Link\LinkResolver;
use Tbtop\Cms\Link\LinkShapeValidator;
use Tbtop\Cms\Mail\CmsMailer;
use Tbtop\Cms\NotFound\Commands\PruneNotFoundHitsCommand;
use Tbtop\Cms\Notifications\Contracts\TemplateStore;
use Tbtop\Cms\Notifications\DeliveryRecorder;
use Tbtop\Cms\Notifications\NotificationRegistry;
use Tbtop\Cms\Notifications\Support\DatabaseTemplateStore;
use Tbtop\Cms\Notifications\TemplateRenderer;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Refs\PageRefRule;
use Tbtop\Cms\Page\Refs\RebuildPageRefsCommand;
use Tbtop\Cms\Redirect\RedirectResolver;
use Tbtop\Cms\Seo\Commands\SnapshotSeoAuditCommand;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\SharedBlock\SharedBlocks;
use Tbtop\Cms\Support\Wiring\AdminWiring;
use Tbtop\Cms\Support\Wiring\ContentEventsWiring;
use Tbtop\Cms\Support\Wiring\ErrorPagesWiring;
use Tbtop\Cms\Support\Wiring\NotificationWiring;
use Tbtop\Cms\Support\Wiring\ScheduleWiring;
use Tbtop\Cms\Telegram\TelegramClient;
use Tbtop\Cms\Telegram\TelegramInvites;

class CmsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tbtop-cms')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('2026_01_01_000000_update_tables_add_tbtop_prefix_table')
            ->hasMigration('2026_01_01_000001_create_languages_table')
            ->hasMigration('2026_01_01_000002_create_pages_table')
            ->hasMigration('2026_01_01_000003_create_page_slugs_table')
            ->hasMigration('2026_01_01_000004_create_cms_user_profiles_table')
            ->hasMigration('2026_01_01_000005_create_redirects_table')
            ->hasMigration('2026_01_01_000006_create_cms_settings_table')
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
            ->hasMigration('2026_08_12_000001_create_not_found_hits_table')
            ->hasMigration('2026_08_17_000000_drop_cms_templates_table')
            ->hasMigration('2026_08_17_000001_update_pages_add_starter_blocks_table')
            ->hasMigration('2026_08_18_000000_create_activity_log_table')
            ->hasMigration('2026_08_27_000000_create_notification_log_table')
            ->hasMigration('2026_08_31_000001_update_pages_add_source_table')
            ->hasMigration('2026_08_31_000002_create_seo_audit_snapshots_table')
            ->hasMigration('2026_09_01_000000_create_notification_templates_table')
            ->hasMigration('2026_09_15_000000_add_error_class_to_pages_table')
            ->hasMigration('2026_09_23_000000_add_og_locale_to_languages_table')
            ->hasMigration('2026_10_03_000002_create_shared_blocks_table')
            ->hasMigration('2026_10_06_000000_add_subtype_and_fields_to_pages_table')
            ->hasMigration('2026_10_07_000000_add_lock_version_to_shared_blocks_table')
            ->hasMigration('2026_10_07_000001_move_identity_contacts_to_site_contacts')
            ->hasMigration('2026_10_07_000002_create_page_refs_table')
            ->hasMigration('2026_10_13_000000_add_payload_order_to_form_submissions_table')
            ->hasCommands([
                InstallCommand::class,
                CreateAdminCommand::class,
                GrantAdminCommand::class,
                RevokeAdminCommand::class,
                PruneNotFoundHitsCommand::class,
                SnapshotSeoAuditCommand::class,
                DoctorCommand::class,
                BlocksTypesCommand::class,
                RebuildPageRefsCommand::class,
            ])
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->register(ActivitylogServiceProvider::class);
        $this->loadTranslations();
        $this->app->scoped(Languages::class);
        $this->app->singleton(PageTypeRegistry::class);
        $this->app->singleton(BlockRegistry::class);
        $this->app->singleton(RobotsRegistry::class);
        $this->app->singleton(SchemaTypeRegistry::class);
        $this->app->scoped(BlockContentResolver::class);
        $this->app->scoped(SharedBlocks::class);
        $this->app->scoped(LayoutResolver::class);
        $this->app->scoped(PageResolver::class);
        $this->app->scoped(RedirectResolver::class);
        $this->app->scoped(ErrorPageResolver::class);
        $this->app->scoped(ErrorResponse::class);
        $this->app->scoped(CurrentPage::class);
        $this->app->scoped(Settings::class);
        $this->app->scoped(CmsMailer::class);
        $this->app->scoped(Cms::class);
        $this->app->scoped(CmsCache::class);
        $this->app->scoped(TelegramClient::class);
        $this->app->scoped(TelegramInvites::class);
        $this->app->scoped(SubmissionNotifier::class);
        $this->app->scoped(AuditContext::class);
        $this->app->singleton(NotificationRegistry::class);
        $this->app->scoped(DeliveryRecorder::class);
        $this->app->scoped(TemplateRenderer::class);
        $this->app->bind(TemplateStore::class, DatabaseTemplateStore::class);
        $this->app->scoped(LinkResolver::class);
        $this->registerLinkField();
        AdminWiring::registerPanel();
    }

    public function packageBooted(): void
    {
        $this->forceSsrOffByDefault();
        $this->gateSsrByRequest();
        AdminWiring::wire($this->app);
        LinkShapeValidator::register();
        PageRefRule::register();
        ContentEventsWiring::wire($this->app);
        NotificationWiring::wire($this->app);
        ErrorPagesWiring::wire($this->app);
        ScheduleWiring::wire($this->app);
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

    /** Kind registration only — the shape rule waits for boot, when the validator factory exists. */
    private function registerLinkField(): void
    {
        S::register('link', Link::class);
        S::register('pages', Pages::class);
    }

    /**
     * inertia-laravel's own config defaults `inertia.ssr.enabled` to true
     * (`config/inertia.php`: `env('INERTIA_SSR_ENABLED', true)`), and hosts
     * built against this package typically don't publish their own
     * `config/inertia.php`, so that vendor default is what's actually in
     * effect. Keep the global setting stable for the worker lifetime and let
     * HttpGateway's disable callback gate dispatch using EnableSsr's
     * request-local marker. Admin and other host responses are never marked.
     *
     * Applied unconditionally — a published `config/inertia.php` is NOT
     * treated as an opt-out, because the vendor stub it is copied from
     * defaults `enabled` to true: a host publishing the file for an
     * unrelated tweak (ssr port, bundle path) would silently route the
     * admin through SSR. A host that genuinely wants global SSR keeps the
     * standard escape hatch: set `config(['inertia.ssr.enabled' => true])`
     * in its own service provider's boot() — application providers boot
     * after discovered package providers, so that assignment wins.
     *
     * The flag mirrors `tbtop-cms.ssr.enabled`: `inertia:start-ssr` refuses
     * to boot the SSR process when
     * `inertia.ssr.enabled` is false (StartSsr::handle()'s first check), and
     * it is false, while HTTP dispatch is isolated by the request marker.
     */
    private function forceSsrOffByDefault(): void
    {
        config(['inertia.ssr.enabled' => (bool) config('tbtop-cms.ssr.enabled', false)]);
    }

    private function gateSsrByRequest(): void
    {
        $this->app->make(HttpGateway::class)->disable(
            static fn (): bool => ! (bool) request()->attributes->get(EnableSsr::REQUEST_ATTRIBUTE, false),
        );
    }
}
