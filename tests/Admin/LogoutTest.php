<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
});

// Regression: the logout endpoint previously didn't exist at all — every
// logout click fell through to the frontend's GET-only catch-all and 405'd.
// The route lives under the panel prefix, matching the admin client's
// (>=0.2.24) panel-prefixed ProfileDropdown default.
it('logs the admin out and redirects to the login page', function (): void {
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);
    test()->actingAs($user, 'web');

    test()->post('/admin/logout')->assertRedirect('/admin/login');

    expect(auth('web')->check())->toBeFalse();
});

it('invalidates the session on logout', function (): void {
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);
    test()->actingAs($user, 'web');

    $originalSessionId = session()->getId();

    test()->post('/admin/logout');

    expect(session()->getId())->not->toBe($originalSessionId);
});

it('redirects a guest hitting logout to the login redirect route instead of authenticating', function (): void {
    // auth:{guard} middleware's default unauthenticated behavior: redirect to
    // the named 'login' route — the Route::redirect('login', ...) registered
    // by wireAdminLoginRedirect(), one hop from /admin/login — not a 401/405,
    // same as hitting any other guarded admin route while signed out.
    test()->post('/admin/logout')->assertRedirect('/login');

    expect(auth('web')->check())->toBeFalse();
});
