<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
});

it('creates a user and grants admin access', function (): void {
    $this->artisan('cms:admin:create', ['email' => 'a@example.com', '--password' => 'secret'])
        ->assertExitCode(0);

    $user = AdminUser::where('email', 'a@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(CmsUserProfile::where('user_id', $user->id)->exists())->toBeTrue();
});

it('creates the admin idempotently', function (): void {
    $this->artisan('cms:admin:create', ['email' => 'a@example.com', '--password' => 'x'])->assertExitCode(0);
    $this->artisan('cms:admin:create', ['email' => 'a@example.com', '--password' => 'x'])->assertExitCode(0);

    expect(AdminUser::where('email', 'a@example.com')->count())->toBe(1)
        ->and(CmsUserProfile::count())->toBe(1);
});

it('grants then revokes access for an existing user', function (): void {
    $user = AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);

    $this->artisan('cms:admin:grant', ['email' => 'a@example.com'])->assertExitCode(0);
    expect(CmsUserProfile::where('user_id', $user->id)->exists())->toBeTrue();

    $this->artisan('cms:admin:revoke', ['email' => 'a@example.com'])->assertExitCode(0);
    expect(CmsUserProfile::where('user_id', $user->id)->exists())->toBeFalse();
});

it('fails to grant access to a missing user', function (): void {
    $this->artisan('cms:admin:grant', ['email' => 'missing@example.com'])->assertExitCode(1);
});
