<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
});

function actingAsSharedAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Dana', 'email' => 'dana@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);

    test()->actingAs($user, 'web');

    return $user;
}

it('shares the signed-in admin as auth.user', function (): void {
    actingAsSharedAdmin();

    $response = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.auth.user.name'))->toBe('Dana')
        ->and($response->json('props.auth.user.email'))->toBe('dana@example.com');
});

it('shares only name and email, never the password', function (): void {
    actingAsSharedAdmin();

    $response = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk();

    expect(array_keys((array) $response->json('props.auth.user')))
        ->toEqualCanonicalizing(['name', 'email']);
});
