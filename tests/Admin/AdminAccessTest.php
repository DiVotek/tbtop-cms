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

it('redirects a guest to the login route', function (): void {
    $this->get('/admin/dashboard')->assertRedirect(route('login'));
});

it('forbids an authenticated user without a CMS profile', function (): void {
    $user = AdminUser::create(['name' => 'A', 'email' => 'no-profile@example.com', 'password' => 'secret']);

    $this->actingAs($user, 'web')->get('/admin/dashboard')->assertForbidden();
});

it('allows an authenticated user with a CMS profile', function (): void {
    $user = AdminUser::create(['name' => 'A', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);

    $this->actingAs($user, 'web')
        ->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk();
});

it('lets a guest reach the login page', function (): void {
    $this->get('/admin/login', ['X-Inertia' => 'true'])->assertOk();
});
