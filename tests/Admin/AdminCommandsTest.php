<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tbtop\Cms\Admin\Events\CmsAccessGranted;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\ContractAdminUser;
use Tbtop\Cms\Tests\Fixtures\GatedContractAdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
});

it('creates a user and grants admin access', function (): void {
    $this->artisan('cms:admin:create', ['email' => 'a@example.com', '--password' => 'secret123'])
        ->assertExitCode(0);

    $user = AdminUser::where('email', 'a@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(CmsUserProfile::where('user_id', $user->id)->exists())->toBeTrue();
});

it('creates the admin idempotently', function (): void {
    $this->artisan('cms:admin:create', ['email' => 'a@example.com', '--password' => 'password123'])->assertExitCode(0);
    $this->artisan('cms:admin:create', ['email' => 'a@example.com', '--password' => 'password123'])->assertExitCode(0);

    expect(AdminUser::where('email', 'a@example.com')->count())->toBe(1)
        ->and(CmsUserProfile::count())->toBe(1);
});

it('rolls back a newly created user when granting access fails', function (): void {
    Event::listen(CmsAccessGranted::class, static fn (): never => throw new RuntimeException('Grant failed.'));

    expect(fn () => $this->artisan('cms:admin:create', [
        'email' => 'failed@example.com',
        '--password' => 'password123',
    ]))->toThrow(RuntimeException::class, 'Grant failed.');

    expect(AdminUser::where('email', 'failed@example.com')->exists())->toBeFalse()
        ->and(CmsUserProfile::count())->toBe(0);
});

it('rejects creating a user with a password shorter than 8 characters', function (): void {
    $this->artisan('cms:admin:create', ['email' => 'a@example.com', '--password' => 'short'])
        ->assertExitCode(1);

    expect(AdminUser::where('email', 'a@example.com')->exists())->toBeFalse();
});

it('warns when creating a CmsAdmin-contract user whose access is controlled by the application', function (): void {
    config(['tbtop-cms.admin.model' => GatedContractAdminUser::class]);

    $this->artisan('cms:admin:create', ['email' => 'denied@example.com', '--password' => 'password123'])
        ->assertExitCode(0)
        ->expectsOutputToContain('implements CmsAdmin; access is controlled by canAccessCmsAdmin()');
});

it('grants then revokes access for an existing user', function (): void {
    // The very first profile row ever becomes the protected owner (see
    // UsersOwnerTest) and could not be revoked — grant an unrelated first
    // user so `a@example.com` below is not that row.
    AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x']);
    $this->artisan('cms:admin:grant', ['email' => 'owner@example.com'])->assertExitCode(0);

    $user = AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);

    $this->artisan('cms:admin:grant', ['email' => 'a@example.com'])->assertExitCode(0);
    expect(CmsUserProfile::where('user_id', $user->id)->exists())->toBeTrue();

    $this->artisan('cms:admin:revoke', ['email' => 'a@example.com'])->assertExitCode(0);
    expect(CmsUserProfile::where('user_id', $user->id)->exists())->toBeFalse();
});

it('fails to grant access to a missing user', function (): void {
    $this->artisan('cms:admin:grant', ['email' => 'missing@example.com'])->assertExitCode(1);
});

it('warns instead of silently no-op-ing when granting a CmsAdmin-contract user', function (): void {
    config(['tbtop-cms.admin.model' => ContractAdminUser::class]);
    ContractAdminUser::create(['name' => 'A', 'email' => 'contract@example.com', 'password' => 'x']);

    $this->artisan('cms:admin:grant', ['email' => 'contract@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('implements CmsAdmin; access is controlled by canAccessCmsAdmin()');
});

it('warns instead of silently no-op-ing when revoking a CmsAdmin-contract user', function (): void {
    config(['tbtop-cms.admin.model' => ContractAdminUser::class]);
    ContractAdminUser::create(['name' => 'A', 'email' => 'contract@example.com', 'password' => 'x']);

    $this->artisan('cms:admin:revoke', ['email' => 'contract@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('implements CmsAdmin; access is controlled by canAccessCmsAdmin()');
});

it('fails to revoke the owner via the CLI instead of a false success message', function (): void {
    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x']);
    $this->artisan('cms:admin:grant', ['email' => 'owner@example.com'])->assertExitCode(0);

    $this->artisan('cms:admin:revoke', ['email' => 'owner@example.com'])
        ->assertExitCode(1)
        ->expectsOutputToContain('protected owner');

    expect(CmsUserProfile::where('user_id', $owner->id)->exists())->toBeTrue();
});
