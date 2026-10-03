<?php

declare(strict_types=1);
use Tbtop\Cms\Admin\Pages\AuditActivityPage;
use Tbtop\Cms\Admin\Pages\AuditLogPage;
use Tbtop\Cms\Admin\Pages\BlocksPage;
use Tbtop\Cms\Admin\Pages\DashboardPage;
use Tbtop\Cms\Admin\Pages\FormSubmissionPage;
use Tbtop\Cms\Admin\Pages\FormSubmissionsPage;
use Tbtop\Cms\Admin\Pages\LanguagesPage;
use Tbtop\Cms\Admin\Pages\LayoutEditPage;
use Tbtop\Cms\Admin\Pages\LayoutPage;
use Tbtop\Cms\Admin\Pages\LoginPage;
use Tbtop\Cms\Admin\Pages\MediaLibraryPage;
use Tbtop\Cms\Admin\Pages\NotFoundLogPage;
use Tbtop\Cms\Admin\Pages\NotificationSettingsPage;
use Tbtop\Cms\Admin\Pages\PageEditPage;
use Tbtop\Cms\Admin\Pages\PagesPage;
use Tbtop\Cms\Admin\Pages\ProfilePage;
use Tbtop\Cms\Admin\Pages\RedirectsPage;
use Tbtop\Cms\Admin\Pages\SeoReportPage;
use Tbtop\Cms\Admin\Pages\SeoSettingsPage;
use Tbtop\Cms\Admin\Pages\SharedBlockEditPage;
use Tbtop\Cms\Admin\Pages\SharedBlocksPage;
use Tbtop\Cms\Admin\Pages\SiteHealthPage;
use Tbtop\Cms\Admin\Pages\SiteSettingsPage;
use Tbtop\Cms\Admin\Pages\UsersPage;
use Tbtop\Cms\Frontend\Http\Middleware\SetLanguage;
use Tbtop\Cms\NotFound\Support\NotFoundNoise;

return [
    'languages' => [
        'seed' => [
            [
                'name' => 'English',
                'locale' => 'en',
                'is_default' => true,
                'is_admin_active' => true,
                'is_frontend_active' => true,
                'sort_order' => 0,
            ],
        ],
    ],
    'pages' => [
        'types' => [],
        'home' => [
            'name' => 'Home',
            'title' => 'Home',
        ],
        'errors' => [
            '4xx' => [
                'name' => 'Page not found',
                'title' => 'Page not found',
            ],
            '5xx' => [
                'name' => 'Something went wrong',
                'title' => 'Something went wrong',
            ],
        ],
    ],
    'blocks' => [
        // Block classes listed by hand. Keep for blocks outside the discovered
        // folders; everything else goes through 'discover'.
        'types' => [],
        // Folders scanned for Block subclasses, namespace => directory, e.g.
        // ['App\\Cms\\Blocks' => app_path('Cms/Blocks')]. Subfolders map to
        // sub-namespaces; traits and abstract classes are skipped. Registered
        // in path order, after 'types'.
        'discover' => [],
        'strict' => false,
        // Where `cms:blocks:types` writes the generated zod schemas / TS types.
        // Null means resources/js/cms/blocks.generated.ts; --path= overrides.
        'types_path' => null,
        // The generated catalog + component registry. Null means
        // registry.generated.ts next to the types file.
        'registry_path' => null,
        // Import prefix of the block components, relative to the registry
        // file; <Kind>.tsx exporting <Kind> is expected under it.
        'components_dir' => './blocks',
    ],
    'seo' => [
        // Host schema.org node classes merged into SchemaTypeRegistry.
        'schema_types' => [],
        // Extra paths robots.txt closes to crawlers, on top of the admin
        // prefix and /login the package owns. Read with an inline default —
        // published host configs override whole sections.
        'robots' => [
            'disallow' => [],
        ],
        'sitemap' => [
            'enabled' => true,
            // URLs per chunk file. The spec's ceiling is 50 000, but the real
            // limit on a CMS is the request that builds the file: every page
            // in a chunk is loaded with its slugs and resolved to one URL per
            // locale, so a large chunk times out long before it is rejected
            // for being oversized. Read with an inline default — published
            // host configs override whole sections and won't have this key.
            'per_chunk' => 1000,
        ],
    ],
    'preview' => [
        // Hours a signed draft-preview link stays valid for. Read with an
        // inline default (config('tbtop-cms.preview.link_ttl_hours', 72)) —
        // published host configs override whole sections and won't have this key.
        'link_ttl_hours' => 72,
    ],
    'cache' => [
        // Cross-request cache for frontend content reads (settings, languages,
        // layout). Admin reads always stay live regardless of this flag.
        'enabled' => true,
        // Seconds a cached entry lives before recomputing.
        'ttl' => 3600,
        // Cache store name to use (see config/cache.php stores). Null uses the
        // host application's default store.
        'store' => null,
    ],
    'settings' => [
        'secret' => [
            'mail.password',
            'notifications.telegram.bot_token',
        ],
    ],
    'frontend' => [
        'middleware' => [
            SetLanguage::class,
        ],
    ],
    'ssr' => [
        // Controls Inertia server-side rendering for cms frontend responses
        // only. Leave the host's global `inertia.ssr.enabled` at its default
        // (false) — flipping that instead would route admin responses
        // through SSR too. See EnableSsr and the README's "Server-side
        // rendering" section.
        'enabled' => (bool) env('TBTOP_CMS_SSR', false),
    ],
    'forms' => [
        'throttle' => '10,1',
        'store' => true,
        // Honeypot field name exposed to public form blocks via
        // FormActionBinder (props.honeypotField) and enforced by
        // FormSubmitController. Bots that autofill every field trip it;
        // real visitors never see or fill it. Set to null or false to
        // disable the check (and stop exposing the prop) entirely.
        'honeypot_field' => 'website',
    ],
    'redirects' => [
        // External redirect targets are rejected unless their host is listed here.
        // Exact, case-insensitive match only — listing "example.com" does NOT allow "sub.example.com".
        'allowed_external_hosts' => [],
    ],
    'errors' => [
        // Registers a renderable on the host's exception handler for the
        // listed statuses (see CmsServiceProvider::wireErrorPages()).
        // Any status not listed here falls through to stock Laravel.
        'handle' => true,
        'statuses' => [404, 500, 503],
    ],
    'not_found' => [
        // Case-insensitive substring match against the requested path; a hit
        // is dropped before it reaches the counter. Read with an inline
        // default (Tbtop\Cms\NotFound\Support\NotFoundNoise::DEFAULT_IGNORE)
        // — published host configs override whole sections and won't have
        // this key. The on/off switch itself lives in Settings
        // (seo.not_found.enabled), not here — see RecordsNotFoundHit.
        'ignore' => NotFoundNoise::DEFAULT_IGNORE,
        // Daily cms:not-found:prune scheduling, wired by the package itself
        // (see CmsServiceProvider::wireNotFoundPruneSchedule()). Read with an
        // inline default (config('tbtop-cms.not_found.auto_prune', true)) —
        // published host configs override whole sections and won't have this
        // key. Set to false to prune some other way, or not at all.
        'auto_prune' => true,
    ],
    'revisions' => [
        // Number of content-revision snapshots kept per page (oldest pruned
        // past this). Read with an inline default
        // (config('tbtop-cms.revisions.keep', 25)) — published host configs
        // override whole sections and won't have this key. Set to 0 or null
        // to disable the revisions feature entirely: no snapshots are taken,
        // not even on page creation.
        'keep' => 25,
    ],
    'audit' => [
        'retention_days' => 365,
        'auto_prune' => true,
    ],
    'profile' => [
        // Disable on hosts where passwords are managed elsewhere (SSO).
        'password_change' => true,
    ],
    'render' => [
        'component' => 'Cms/Page',
        'root_kind' => 'Fragment',
        'root_view' => null,
    ],
    // Who answers the tbtop-cms.* section abilities: false leaves every
    // section open to anyone with admin access; true hands them to the host,
    // which must define the gates first — an unanswered ability denies.
    'roles' => false,
    'admin' => [
        'autoregister' => true,
        'prefix' => 'admin',
        'guard' => 'web',
        'model' => 'App\\Models\\User',
        'pages' => [
            'dashboard' => DashboardPage::class,
            'profile' => ProfilePage::class,
            'login' => LoginPage::class,
            'languages' => LanguagesPage::class,
            'pages' => PagesPage::class,
            'page_edit' => PageEditPage::class,
            'redirects' => RedirectsPage::class,
            'not_found' => NotFoundLogPage::class,
            'seo_report' => SeoReportPage::class,
            'media_library' => MediaLibraryPage::class,
            'site_settings' => SiteSettingsPage::class,
            'seo_settings' => SeoSettingsPage::class,
            'notification_settings' => NotificationSettingsPage::class,
            'site_health' => SiteHealthPage::class,
            'layout' => LayoutPage::class,
            'layout_edit' => LayoutEditPage::class,
            'shared_blocks' => SharedBlocksPage::class,
            'shared_block_edit' => SharedBlockEditPage::class,
            'blocks' => BlocksPage::class,
            'form_submissions' => FormSubmissionsPage::class,
            'form_submission' => FormSubmissionPage::class,
            'users' => UsersPage::class,
            'audit_log' => AuditLogPage::class,
            'audit_activity' => AuditActivityPage::class,
        ],
    ],
];
