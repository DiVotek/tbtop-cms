<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Models\Setting;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\ContractAdminUser;

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
    // Not Activity::query()->sole(): this same POST also touches
    // notifications.form.submission.channels (NotificationSettingsPage maps
    // one channels__* field per registered type; it defaults to [] and, like
    // any first-touch setting, gets its own audit row) — this test only
    // cares about the secret-redaction behavior on mail.password.
    $activity = Activity::query()->forSubject($setting)->sole();

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

it('offers a recorded profile-less CmsAdmin actor in the author filter', function (): void {
    config(['tbtop-cms.admin.model' => ContractAdminUser::class]);
    config(['auth.providers.users.model' => ContractAdminUser::class]);

    $admin = ContractAdminUser::create([
        'name' => 'Contract Admin',
        'email' => 'contract@example.com',
        'password' => 'secret',
    ]);

    activity('tbtop-cms')->causedBy($admin)->log('Test activity');
    test()->actingAs($admin, 'web');

    $structure = test()->get('/admin/audit-log', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');
    $authorFilter = collect(data_get($structure, 'options.children.0.options.filters'))
        ->firstWhere('name', 'causer_id');

    expect($authorFilter['options']['options'] ?? [])->toContain([
        'value' => (string) $admin->id,
        'label' => 'Contract Admin',
    ]);
});
