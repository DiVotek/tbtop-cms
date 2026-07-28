<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function actingAsSectionAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->create(['user_id' => $user->id]);
    test()->actingAs($user, 'web');

    return $user;
}

it('opens every section to anyone with admin access when roles are off', function (string $path): void {
    config(['tbtop-cms.roles' => false]);
    actingAsSectionAdmin();

    test()->get('/admin/' . $path)->assertOk();
})->with(['pages', 'media-library', 'form-submissions', 'redirects', 'templates', 'menus', 'languages', 'users']);

it('hands the section ability to the host when roles are on', function (): void {
    config(['tbtop-cms.roles' => true]);
    Gate::define('tbtop-cms.pages', static fn (): bool => true);
    Gate::define('tbtop-cms.redirects', static fn (): bool => false);
    actingAsSectionAdmin();

    test()->get('/admin/pages')->assertOk();
    test()->get('/admin/redirects')->assertForbidden();
});

// The package defines no section gates, so an ability the host has not
// answered denies rather than silently passing.
it('denies a section the host left unanswered when roles are on', function (): void {
    config(['tbtop-cms.roles' => true]);
    actingAsSectionAdmin();

    test()->get('/admin/pages')->assertForbidden();
});

it('keeps admin access independent of the roles flag', function (bool $roles): void {
    config(['tbtop-cms.roles' => $roles]);
    $stranger = AdminUser::create(['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'secret']);

    test()->actingAs($stranger, 'web')->get('/admin/dashboard')->assertForbidden();
})->with([
    'roles off' => [false],
    'roles on' => [true],
]);
