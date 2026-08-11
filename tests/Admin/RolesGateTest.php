<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\ContractAdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function actingAsRole(CmsRole $role): AdminUser
{
    static $n = 0;
    $n++;
    $user = AdminUser::create(['name' => 'User ' . $n, 'email' => "role{$n}@example.com", 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => $role]);
    test()->actingAs($user, 'web');

    return $user;
}

dataset('editor_allowed_pages', [
    'dashboard' => ['/admin/dashboard'],
    'profile' => ['/admin/profile'],
    'pages' => ['/admin/pages'],
    'media_library' => ['/admin/media-library'],
]);

it('lets an editor reach dashboard/profile/pages/media_library', function (string $url): void {
    actingAsRole(CmsRole::Editor);

    test()->get($url, ['X-Inertia' => 'true'])->assertOk();
})->with('editor_allowed_pages');

dataset('editor_gated_pages', [
    'redirects' => ['/admin/redirects'],
    'site_settings' => ['/admin/site-settings'],
    'seo_settings' => ['/admin/seo-settings'],
    'notification_settings' => ['/admin/notification-settings'],
    'languages' => ['/admin/languages'],
    'templates' => ['/admin/templates'],
    'layout' => ['/admin/layout'],
    'blocks' => ['/admin/blocks'],
    'users' => ['/admin/users'],
]);

it('403s an editor on administrator-only pages', function (string $url): void {
    actingAsRole(CmsRole::Editor);

    test()->get($url, ['X-Inertia' => 'true'])->assertForbidden();
})->with('editor_gated_pages');

dataset('administrator_pages', [
    'dashboard' => ['/admin/dashboard'],
    'profile' => ['/admin/profile'],
    'pages' => ['/admin/pages'],
    'media_library' => ['/admin/media-library'],
    'redirects' => ['/admin/redirects'],
    'site_settings' => ['/admin/site-settings'],
    'seo_settings' => ['/admin/seo-settings'],
    'notification_settings' => ['/admin/notification-settings'],
    'languages' => ['/admin/languages'],
    'templates' => ['/admin/templates'],
    'layout' => ['/admin/layout'],
    'blocks' => ['/admin/blocks'],
    'users' => ['/admin/users'],
]);

it('lets an administrator reach every gated page, including users', function (string $url): void {
    actingAsRole(CmsRole::Administrator);

    test()->get($url, ['X-Inertia' => 'true'])->assertOk();
})->with('administrator_pages');

it('lets a superadministrator reach every gated page, including users', function (string $url): void {
    actingAsRole(CmsRole::SuperAdministrator);

    test()->get($url, ['X-Inertia' => 'true'])->assertOk();
})->with('administrator_pages');

it('keeps the login page ungated for a guest', function (): void {
    test()->get('/admin/login', ['X-Inertia' => 'true'])->assertOk();
});

it('treats a CmsAdmin-contract user without a profile row as an implicit superadministrator', function (string $url): void {
    // Before roles existed, contract-based access (canAccessCmsAdmin) meant
    // full admin access with no CmsUserProfile row required. That must
    // survive the upgrade: no row → implicit superadministrator.
    config(['tbtop-cms.admin.model' => ContractAdminUser::class]);
    config(['auth.providers.users.model' => ContractAdminUser::class]);
    $user = ContractAdminUser::create(['name' => 'Contract', 'email' => 'contract@example.com', 'password' => 'secret']);

    test()->actingAs($user, 'web')->get($url, ['X-Inertia' => 'true'])->assertOk();
})->with('administrator_pages');

it('does not demote an implicit superadministrator when saving their profile creates the row', function (): void {
    // Regression (Devin, PR #67): profile saves go through
    // CurrentAdmin::profile() (firstOrNew) — for a contract user with no
    // row, the INSERT used to land with the DB default role 'editor',
    // silently stripping their implicit superadministrator access the
    // first time they changed a display name or theme.
    config(['tbtop-cms.admin.model' => ContractAdminUser::class]);
    config(['auth.providers.users.model' => ContractAdminUser::class]);
    $user = ContractAdminUser::create(['name' => 'Contract', 'email' => 'contract3@example.com', 'password' => 'secret']);

    test()->actingAs($user, 'web')
        ->post('/admin/profile/forms/profile', ['display_name' => 'Renamed'])
        ->assertRedirect();

    expect(CmsUserProfile::query()->where('user_id', $user->id)->value('role'))
        ->toBe(CmsRole::SuperAdministrator);

    test()->actingAs($user, 'web')->get('/admin/users', ['X-Inertia' => 'true'])->assertOk();
});

it('lets a profile row downgrade a CmsAdmin-contract user to editor', function (): void {
    config(['tbtop-cms.admin.model' => ContractAdminUser::class]);
    config(['auth.providers.users.model' => ContractAdminUser::class]);
    $user = ContractAdminUser::create(['name' => 'Contract', 'email' => 'contract2@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::Editor]);

    test()->actingAs($user, 'web')->get('/admin/redirects', ['X-Inertia' => 'true'])->assertForbidden();
    test()->actingAs($user, 'web')->get('/admin/pages', ['X-Inertia' => 'true'])->assertOk();
});

it('excludes gated nav items from an editor shared nav but includes them for an administrator', function (): void {
    actingAsRole(CmsRole::Editor);
    $editorNav = test()->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk()->json('props.tbtop.nav');
    $editorHrefs = collect($editorNav)->pluck('items')->flatten(1)->pluck('href')->all();

    expect($editorHrefs)->toContain('/admin/pages', '/admin/media-library')
        ->and($editorHrefs)->not->toContain('/admin/users', '/admin/languages', '/admin/redirects', '/admin/blocks', '/admin/templates', '/admin/layout');

    actingAsRole(CmsRole::Administrator);
    $adminNav = test()->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk()->json('props.tbtop.nav');
    $adminHrefs = collect($adminNav)->pluck('items')->flatten(1)->pluck('href')->all();

    expect($adminHrefs)->toContain('/admin/pages', '/admin/media-library', '/admin/users', '/admin/languages', '/admin/redirects', '/admin/blocks', '/admin/templates', '/admin/layout');
});

it('gives every sidebar nav item a lucide icon (5.13)', function (): void {
    actingAsRole(CmsRole::SuperAdministrator);
    $nav = test()->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk()->json('props.tbtop.nav');
    $items = collect($nav)->pluck('items')->flatten(1);
    $iconByHref = $items->pluck('icon.name', 'href');

    expect($iconByHref['/admin/pages'])->toBe('files')
        ->and($iconByHref['/admin/redirects'])->toBe('corner-up-right')
        ->and($iconByHref['/admin/media-library'])->toBe('image')
        ->and($iconByHref['/admin/templates'])->toBe('layout-template')
        ->and($iconByHref['/admin/layout'])->toBe('panels-top-left')
        ->and($iconByHref['/admin/blocks'])->toBe('blocks')
        ->and($iconByHref['/admin/languages'])->toBe('languages')
        ->and($iconByHref['/admin/site-settings'])->toBe('settings')
        ->and($iconByHref['/admin/users'])->toBe('users')
        ->and($iconByHref['/admin/seo-settings'])->toBe('search')
        ->and($iconByHref['/admin/notification-settings'])->toBe('bell');
});
