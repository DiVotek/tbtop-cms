<?php

declare(strict_types=1);
use Tbtop\Cms\Admin\Pages\DashboardPage;
use Tbtop\Cms\Admin\Pages\LoginPage;
use Tbtop\Cms\Admin\Pages\ProfilePage;
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
    'frontend' => [
        'middleware' => [
            SetLanguage::class,
        ],
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
        ],
    ],
];
