<?php

declare(strict_types=1);

namespace Tbtop\Cms;

use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Inertia\Ssr\HttpGateway;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\CmsPanel;
use Tbtop\Cms\Admin\Commands\CreateAdminCommand;
use Tbtop\Cms\Admin\Commands\GrantAdminCommand;
use Tbtop\Cms\Admin\Commands\RevokeAdminCommand;
use Tbtop\Cms\Admin\Fields\Link;
use Tbtop\Cms\Admin\Fields\Pages;
use Tbtop\Cms\Admin\Http\Controllers\LogoutController;
use Tbtop\Cms\Admin\Http\Middleware\EnsureCmsAdmin;
use Tbtop\Cms\Admin\Listeners\SeedsLocaleFromProfile;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Cache\InvalidatesContentCache;
use Tbtop\Cms\Console\BlocksTypesCommand;
use Tbtop\Cms\Console\DoctorCommand;
use Tbtop\Cms\Console\InstallCommand;
use Tbtop\Cms\Form\Events\SubmissionReceived;
use Tbtop\Cms\Form\Listeners\NotifiesAdminsOfSubmission;
use Tbtop\Cms\Form\Notifications\FormSubmissionPayload;
use Tbtop\Cms\Form\SubmissionNotifier;
use Tbtop\Cms\Frontend\Errors\ErrorPageResolver;
use Tbtop\Cms\Frontend\Errors\ErrorResponse;
use Tbtop\Cms\Frontend\Http\Middleware\EnableSsr;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Frontend\Seo\RobotsRegistry;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeRegistry;
use Tbtop\Cms\Frontend\Support\CurrentPage;
use Tbtop\Cms\Health\Checks\SchedulerHeartbeatCheck;
use Tbtop\Cms\Language\Events\LanguagesChanged;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Events\LayoutSaved;
use Tbtop\Cms\Layout\LayoutResolver;
use Tbtop\Cms\Link\Http\LinkPageSearchController;
use Tbtop\Cms\Link\LinkPageSearchRoute;
use Tbtop\Cms\Link\LinkResolver;
use Tbtop\Cms\Link\LinkShapeValidator;
use Tbtop\Cms\Mail\CmsMailer;
use Tbtop\Cms\NotFound\Commands\PruneNotFoundHitsCommand;
use Tbtop\Cms\NotFound\Events\PageNotFound;
use Tbtop\Cms\NotFound\Listeners\RecordsNotFoundHit;
use Tbtop\Cms\Notifications\Audience;
use Tbtop\Cms\Notifications\Channels\BellChannel;
use Tbtop\Cms\Notifications\Channels\MailChannel;
use Tbtop\Cms\Notifications\Channels\TelegramChannel;
use Tbtop\Cms\Notifications\Contracts\TemplateStore;
use Tbtop\Cms\Notifications\DeliveryRecorder;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\NotificationRegistry;
use Tbtop\Cms\Notifications\Notifications;
use Tbtop\Cms\Notifications\Support\DatabaseTemplateStore;
use Tbtop\Cms\Notifications\TemplateRenderer;
use Tbtop\Cms\Notifications\TypeDefinition;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Events\PagePublished;
use Tbtop\Cms\Page\Events\PageRestored;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Events\PageUnpublished;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\Observers\PageRefs;
use Tbtop\Cms\Page\Observers\PageRevisions;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Refs\PageRefRule;
use Tbtop\Cms\Page\Refs\RebuildPageRefsCommand;
use Tbtop\Cms\Page\Support\PageSlugWriter;
use Tbtop\Cms\Redirect\Observers\PageSlugRedirects;
use Tbtop\Cms\Redirect\RedirectResolver;
use Tbtop\Cms\Seo\Commands\SnapshotSeoAuditCommand;
use Tbtop\Cms\Settings\Events\SettingsSaved;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\SharedBlock\Events\SharedBlockDeleted;
use Tbtop\Cms\SharedBlock\Events\SharedBlockSaved;
use Tbtop\Cms\SharedBlock\SharedBlocks;
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
        $this->registerAdminPanel();
    }

    public function packageBooted(): void
    {
        $this->forceSsrOffByDefault();
        $this->gateSsrByRequest();
        $this->wireAdminLoginRedirect();
        $this->wireAdminLogout();
        $this->wireLinkPageSearch();
        LinkShapeValidator::register();
        PageRefRule::register();
        $this->wireLocaleSlugBackfill();
        $this->wireRedirectMaintenance();
        $this->wirePageRevisions();
        $this->wirePageRefs();
        $this->wireContentCacheInvalidation();
        $this->wireLocaleSeeding();
        $this->wireNotificationRegistry();
        $this->wireSubmissionNotifications();
        $this->wireNotFoundLogging();
        $this->wireErrorPages();
        $this->wireNotFoundPruneSchedule();
        $this->wireAuditPruneSchedule();
        $this->wireSchedulerHeartbeat();
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

    /**
     * Guarded admin routes rely on a named `login` route: `auth`'s
     * unauthenticated handler redirects there. A bare host has none, so cms
     * provides one pointing at the admin login page.
     *
     * A host that ships its own auth (a Laravel starter kit, Fortify,
     * Breeze) already defines `login`, and registering a second route under
     * that name is actively harmful: Laravel keeps the first binding for
     * URL generation but the last matching route wins dispatch, so
     * `route('login')` would still print the host's URL while GET /login
     * bounced to the admin panel — the host's own login page unreachable.
     * It also emits a duplicate export in Wayfinder's generated routes,
     * breaking the host's frontend build. So it is only registered when
     * nothing else claims the name.
     *
     * The collection is scanned directly rather than asked via Route::has():
     * the name lookup it consults is a cache rebuilt by refreshNameLookups(),
     * and during boot that cache still predates routes the host registered,
     * so has() reports false for a name that is demonstrably present.
     */
    private function wireAdminLoginRedirect(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        if (config('tbtop-cms.admin.pages.login') === null) {
            return;
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === 'login') {
                return;
            }
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

    /** One fixed route, not a per-field query() closure: PrefixedField::rename() cannot carry a closure. */
    private function wireLinkPageSearch(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        Route::middleware(['web', 'auth:' . $guard, EnsureCmsAdmin::class])
            ->post($prefix . '/' . LinkPageSearchRoute::RELATIVE_PATH, LinkPageSearchController::class)
            ->name(LinkPageSearchRoute::NAME);
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

    /** A third Page observer, same reasoning: the references index is derived data. */
    private function wirePageRefs(): void
    {
        Page::observe(PageRefs::class);
    }

    /**
     * Every domain event that changes frontend-visible content bumps the
     * content cache version — see InvalidatesContentCache's docblock for why
     * one blanket bump instead of per-event targeted invalidation.
     * SubmissionReceived is intentionally excluded: submissions aren't content.
     */
    private function wireContentCacheInvalidation(): void
    {
        // The per-request shared-block memo must not outlive a save in the same request (editor actions re-render).
        foreach ([SharedBlockSaved::class, SharedBlockDeleted::class] as $event) {
            Event::listen($event, fn () => $this->app->make(SharedBlocks::class)->forget());
        }

        foreach ([
            PageSaved::class,
            PagePublished::class,
            PageUnpublished::class,
            PageDeleted::class,
            PageRestored::class,
            LayoutSaved::class,
            SharedBlockSaved::class,
            SharedBlockDeleted::class,
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

    /**
     * Registers cms's own built-in channels and its one built-in type
     * (form.submission) with Notifications, the same way a host or another
     * package would from its own service provider — see Notifications'
     * class docblock for the registry this feeds.
     *
     * defaultChannels(['bell']) is the fallback Notifications::enabledChannels()
     * returns until a merchant sets notifications.form.submission.channels
     * via the settings page (cms-4) — so every host that has never touched
     * notification settings keeps bell-only delivery, no mail, no telegram.
     */
    private function wireNotificationRegistry(): void
    {
        Notifications::channel(MailChannel::class);
        Notifications::channel(TelegramChannel::class);
        Notifications::channel(BellChannel::class);

        Notifications::type(
            TypeDefinition::make('form.submission')
                ->label(__('tbtop-cms::notifications.bell.title'))
                ->group('forms')
                ->payload(FormSubmissionPayload::class)
                ->templates([
                    Format::Html->value => 'tbtop-cms::notifications.form-submission.html',
                    Format::Short->value => 'tbtop-cms::notifications.form-submission.short',
                ])
                ->audiences([Audience::Staff])
                ->defaultChannels(['bell']),
        );
    }

    private function wireNotFoundLogging(): void
    {
        Event::listen(PageNotFound::class, RecordsNotFoundHit::class);
    }

    /**
     * Renders the matching error page for a whitelisted status instead of
     * Laravel's stock error view — gated by config so a host can opt out
     * entirely (errors.handle=false) and still call Cms::errorResponse()
     * itself. Registered against HttpExceptionInterface, not Throwable: a
     * non-HTTP exception only reaches here after Laravel's own handler has
     * already converted it (see Handler::prepareException()), so this never
     * duplicates that conversion.
     */
    private function wireErrorPages(): void
    {
        $this->app->afterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            $handler->renderable(function (HttpExceptionInterface $e, Request $request): ?Response {
                return $this->renderError($request, $e->getStatusCode());
            });
        });
    }

    private function renderError(Request $request, int $status): ?Response
    {
        if (! (bool) config('tbtop-cms.errors.handle', true)) {
            return null;
        }

        /** @var list<int> $statuses */
        $statuses = (array) config('tbtop-cms.errors.statuses', [404, 500, 503]);

        if (! in_array($status, $statuses, true)) {
            return null;
        }

        if ($status >= 500 && (bool) config('app.debug', false)) {
            return null;
        }

        // API clients keep Laravel's JSON error body; Inertia XHRs send
        // Accept: text/html, so they are not caught by this check.
        if ($request->expectsJson()) {
            return null;
        }

        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        if ($request->is($prefix) || $request->is($prefix . '/*')) {
            return null;
        }

        return app(Cms::class)->errorResponse($request, $status);
    }

    /**
     * Scheduled by the package, opt-out via config: unlike the scheduler
     * heartbeat (which must exist unconditionally, see below), a host that
     * prunes the 404 log some other way — or wants to keep every row — needs
     * a way to turn this off. Read with an inline default (published host
     * configs won't have the `not_found` key at all, see the config file's
     * comments), so silence here means "keep the package default", not
     * "disable it".
     */
    private function wireNotFoundPruneSchedule(): void
    {
        if (! config('tbtop-cms.not_found.auto_prune', true)) {
            return;
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command(PruneNotFoundHitsCommand::class)->daily()
                ->name('tbtop-cms:not-found:prune');
        });
    }

    private function wireAuditPruneSchedule(): void
    {
        if (! config('tbtop-cms.audit.auto_prune', true)) {
            return;
        }

        $this->app->booted(function (): void {
            $days = max(1, (int) config('tbtop-cms.audit.retention_days', 365));
            $this->app->make(Schedule::class)
                ->command("activitylog:clean tbtop-cms --days={$days} --force")
                ->daily()
                ->name('tbtop-cms:audit:prune');
        });
    }

    /**
     * Writes a timestamp SchedulerHeartbeatCheck reads back — the only way
     * to detect "the host's cron never calls schedule:run" from inside the
     * app itself, since a dead scheduler produces no error, just silence
     * (see PruneNotFoundHitsCommand and any future scheduled command never
     * running). Registered directly on the Schedule singleton rather than
     * requiring host wiring: unlike cms:not-found:prune (an opt-in cadence
     * the host chooses), the heartbeat must exist unconditionally for the
     * health check to have anything to read.
     */
    private function wireSchedulerHeartbeat(): void
    {
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            $schedule->call(static function (): void {
                Cache::forever(SchedulerHeartbeatCheck::CACHE_KEY, now()->toIso8601String());
            })->everyMinute()->name('tbtop-cms:health:scheduler-heartbeat');
        });
    }
}
