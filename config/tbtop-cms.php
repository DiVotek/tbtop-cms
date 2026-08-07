<?php

declare(strict_types=1);
use Tbtop\Cms\Admin\Pages\BlocksPage;
use Tbtop\Cms\Admin\Pages\DashboardPage;
use Tbtop\Cms\Admin\Pages\FormSubmissionsPage;
use Tbtop\Cms\Admin\Pages\LanguagesPage;
use Tbtop\Cms\Admin\Pages\LayoutEditPage;
use Tbtop\Cms\Admin\Pages\LayoutPage;
use Tbtop\Cms\Admin\Pages\LoginPage;
use Tbtop\Cms\Admin\Pages\MediaLibraryPage;
use Tbtop\Cms\Admin\Pages\MenuCreatePage;
use Tbtop\Cms\Admin\Pages\MenuEditPage;
use Tbtop\Cms\Admin\Pages\MenusPage;
use Tbtop\Cms\Admin\Pages\NotificationSettingsPage;
use Tbtop\Cms\Admin\Pages\PageEditPage;
use Tbtop\Cms\Admin\Pages\PagesPage;
use Tbtop\Cms\Admin\Pages\ProfilePage;
use Tbtop\Cms\Admin\Pages\RedirectsPage;
use Tbtop\Cms\Admin\Pages\SeoSettingsPage;
use Tbtop\Cms\Admin\Pages\SiteSettingsPage;
use Tbtop\Cms\Admin\Pages\TemplateCreatePage;
use Tbtop\Cms\Admin\Pages\TemplateEditPage;
use Tbtop\Cms\Admin\Pages\TemplatesPage;
use Tbtop\Cms\Admin\Pages\UsersPage;
use Tbtop\Cms\Frontend\Http\Middleware\SetLanguage;

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
    ],
    'blocks' => [
        'types' => [],
        'strict' => false,
    ],
    'seo' => [
        'sitemap' => [
            'enabled' => true,
        ],
    ],
    'preview' => [
        // Hours a signed draft-preview link stays valid for. Read with an
        // inline default (config('tbtop-cms.preview.link_ttl_hours', 72)) —
        // published host configs override whole sections and won't have this key.
        'link_ttl_hours' => 72,
    ],
    'settings' => [
        'secret' => [
            'mail.password',
        ],
    ],
    'frontend' => [
        'middleware' => [
            SetLanguage::class,
        ],
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
    'revisions' => [
        // Number of content-revision snapshots kept per page (oldest pruned
        // past this). Read with an inline default
        // (config('tbtop-cms.revisions.keep', 25)) — published host configs
        // override whole sections and won't have this key. Set to 0 or null
        // to disable the revisions feature entirely: no snapshots are taken,
        // not even on page creation.
        'keep' => 25,
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
            'media_library' => MediaLibraryPage::class,
            'site_settings' => SiteSettingsPage::class,
            'seo_settings' => SeoSettingsPage::class,
            'notification_settings' => NotificationSettingsPage::class,
            'templates' => TemplatesPage::class,
            'template_create' => TemplateCreatePage::class,
            'template_edit' => TemplateEditPage::class,
            'layout' => LayoutPage::class,
            'layout_edit' => LayoutEditPage::class,
            'menus' => MenusPage::class,
            'menu_create' => MenuCreatePage::class,
            'menu_edit' => MenuEditPage::class,
            'blocks' => BlocksPage::class,
            'form_submissions' => FormSubmissionsPage::class,
            'users' => UsersPage::class,
        ],
    ],
];
