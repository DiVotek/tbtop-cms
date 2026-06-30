<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\SettingsForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

it('loads non-secret values and hides secrets on record', function (): void {
    $settings = app(Settings::class);
    $settings->set('site.name', 'Acme');
    $settings->set('notifications.mail.smtp_password', 'hunter2');

    $record = SettingsForm::record(
        ['site_name' => 'site.name', 'pwd' => 'notifications.mail.smtp_password'],
        $settings,
    );

    expect($record['site_name'])->toBe('Acme')
        ->and($record['pwd'])->toBeNull();
});

it('persists submitted values through the service', function (): void {
    $settings = app(Settings::class);

    SettingsForm::apply(['site_name' => 'New'], ['site_name' => 'site.name'], $settings);

    expect($settings->get('site.name'))->toBe('New');
});

it('keeps the existing secret when the field is left blank', function (): void {
    $settings = app(Settings::class);
    $settings->set('notifications.mail.smtp_password', 'original');

    SettingsForm::apply(['pwd' => ''], ['pwd' => 'notifications.mail.smtp_password'], $settings);

    expect($settings->get('notifications.mail.smtp_password'))->toBe('original');
});

it('overwrites the secret when a value is submitted', function (): void {
    $settings = app(Settings::class);
    $settings->set('notifications.mail.smtp_password', 'original');

    SettingsForm::apply(['pwd' => 'changed'], ['pwd' => 'notifications.mail.smtp_password'], $settings);

    expect($settings->get('notifications.mail.smtp_password'))->toBe('changed');
});

it('shows the settings pages to an admin user', function (): void {
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);

    $this->actingAs($user, 'web')->get('/admin/site-settings', ['X-Inertia' => 'true'])->assertOk();
    $this->actingAs($user, 'web')->get('/admin/seo-settings', ['X-Inertia' => 'true'])->assertOk();
    $this->actingAs($user, 'web')->get('/admin/notification-settings', ['X-Inertia' => 'true'])->assertOk();
});

it('redirects a guest away from the settings pages', function (): void {
    $this->get('/admin/site-settings')->assertRedirect(route('login'));
});
