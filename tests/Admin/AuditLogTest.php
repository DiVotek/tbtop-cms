<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Models\Setting;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

it('audits an administrative change without storing its secret value', function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $admin = AdminUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'secret',
    ]);
    CmsUserProfile::query()->forceCreate(['user_id' => $admin->id]);

    $secret = 'smtp-password-that-must-not-be-logged';
    test()->actingAs($admin, 'web')->post('/admin/notification-settings/forms/notification-settings', [
        'mail_password' => $secret,
    ])->assertRedirect();

    $setting = Setting::query()->where('key', 'mail.password')->firstOrFail();
    $activity = Activity::query()->sole();

    expect($activity->log_name)->toBe('tbtop-cms')
        ->and($activity->event)->toBe('created')
        ->and($activity->subject->is($setting))->toBeTrue()
        ->and($activity->causer->is($admin))->toBeTrue()
        ->and(data_get($activity->properties, 'attributes.value'))->toBe([
            'changed' => true,
            'is_redacted' => true,
        ])
        ->and($activity->properties->toJson())->not->toContain($secret);

    test()->get('/admin/audit-log', ['X-Inertia' => 'true'])->assertOk();
    test()->get("/admin/audit-log/{$activity->id}", ['X-Inertia' => 'true'])->assertOk();
});
