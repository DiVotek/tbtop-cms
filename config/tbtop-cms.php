<?php

declare(strict_types=1);
use Tbtop\Cms\Admin\Pages\DashboardPage;
use Tbtop\Cms\Admin\Pages\FormSubmissionsPage;
use Tbtop\Cms\Admin\Pages\LanguagesPage;
use Tbtop\Cms\Admin\Pages\LayoutEditPage;
use Tbtop\Cms\Admin\Pages\LayoutPage;
use Tbtop\Cms\Admin\Pages\LoginPage;
use Tbtop\Cms\Admin\Pages\MediaLibraryPage;
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
    'settings' => [
        'secret' => [
            'notifications.mail.smtp_password',
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
            'form_submissions' => FormSubmissionsPage::class,
        ],
    ],
];
