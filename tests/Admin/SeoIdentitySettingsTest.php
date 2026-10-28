<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    $this->actingAs($user, 'web');
});

it('rejects a local business without a default-locale street and city', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_identity_type' => 'local_business',
        'seo_identity_street' => ['en' => ''],
        'seo_identity_locality' => ['en' => ''],
    ])->assertSessionHasErrors(['seo_identity_street.en', 'seo_identity_locality.en']);

    expect(app(Settings::class)->get('seo.identity.type'))->toBeNull();
});

it('rejects a custom business type that is not a schema.org type name', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_identity_type' => 'local_business',
        'seo_identity_street' => ['en' => '1 Main St'],
        'seo_identity_locality' => ['en' => 'Kyiv'],
        'seo_identity_subtype' => 'other',
        'seo_identity_subtype_custom' => 'car repair',
    ])->assertSessionHasErrors('seo_identity_subtype_custom');

    expect(app(Settings::class)->get('seo.identity.type'))->toBeNull()
        ->and(app(Settings::class)->get('seo.identity.subtype_custom'))->toBeNull();
});

it('ignores a stale custom business type left behind a curated choice', function (): void {
    test()->post('/admin/seo-settings/forms/seo-settings', [
        'seo_identity_type' => 'local_business',
        'seo_identity_street' => ['en' => '1 Main St'],
        'seo_identity_locality' => ['en' => 'Kyiv'],
        'seo_identity_subtype' => 'AutoRepair',
        'seo_identity_subtype_custom' => 'car repair',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('seo.identity.type'))->toBe('local_business')
        ->and(app(Settings::class)->get('seo.identity.subtype'))->toBe('AutoRepair');
});
