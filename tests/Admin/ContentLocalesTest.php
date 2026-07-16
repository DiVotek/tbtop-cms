<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\ContentLocales;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

it('wires content locales from admin-active languages', function (): void {
    Language::factory()->default()->create(['locale' => 'en', 'sort_order' => 0]);
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true, 'sort_order' => 1]);
    Language::factory()->create(['locale' => 'de', 'is_admin_active' => false, 'is_frontend_active' => false, 'sort_order' => 2]);

    $languages = app(Languages::class);
    $languages->refresh();

    ContentLocales::sync($languages);

    expect(config('tbtop-admin.content_locales'))->toBe(['en', 'uk'])
        ->and(config('tbtop-admin.default_content_locale'))->toBe('en');
});

it('falls back to the first admin-active locale when the persisted default is stale (bypassed the model observer)', function (): void {
    Language::factory()->default()->create(['locale' => 'en', 'sort_order' => 0]);
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true, 'sort_order' => 1]);

    // Simulate a mass update / raw migration that bypasses LanguageObserver, desyncing
    // is_default from is_admin_active (Eloquent mass updates never fire model events).
    Language::query()->where('locale', 'en')->update(['is_admin_active' => false]);

    $languages = app(Languages::class);
    $languages->refresh();

    ContentLocales::sync($languages);

    expect(config('tbtop-admin.content_locales'))->toBe(['uk'])
        ->and(config('tbtop-admin.default_content_locale'))->toBe('uk');
});

it('keeps the configured default when no language is admin-active', function (): void {
    config(['tbtop-admin.content_locales' => ['en']]);
    Language::factory()->create(['locale' => 'fr', 'is_admin_active' => false, 'is_frontend_active' => false]);

    $languages = app(Languages::class);
    $languages->refresh();

    ContentLocales::sync($languages);

    expect(config('tbtop-admin.content_locales'))->toBe(['en']);
});

it('syncs content locales on an admin request', function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true]);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);

    $this->actingAs($user, 'web')->get('/admin/pages', ['X-Inertia' => 'true'])->assertOk();

    expect(config('tbtop-admin.content_locales'))->toBe(['en', 'uk']);
});
