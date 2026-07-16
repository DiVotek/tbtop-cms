<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeUsersPageActor(CmsRole $role, string $email): AdminUser
{
    $user = AdminUser::create(['name' => 'Actor', 'email' => $email, 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => $role]);
    test()->actingAs($user, 'web');

    return $user;
}

function makeTargetUser(string $email, ?CmsRole $role = null): AdminUser
{
    $user = AdminUser::create(['name' => 'Target', 'email' => $email, 'password' => 'secret']);
    if ($role !== null) {
        CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => $role]);
    }

    return $user;
}

it('lets a superadministrator grant administrator access to a user with no cms access', function (): void {
    makeUsersPageActor(CmsRole::SuperAdministrator, 'super@example.com');
    $target = makeTargetUser('target@example.com');

    $response = test()->postJson('/admin/users/actions/userGrantSave', [
        'payload' => ['row' => ['id' => $target->id], 'form' => ['role' => CmsRole::Administrator->value]],
    ]);

    $response->assertOk();
    expect(CmsUserProfile::query()->where('user_id', $target->id)->first()?->role)->toBe(CmsRole::Administrator);
});

it('lets an administrator grant editor access to a user with no cms access', function (): void {
    makeUsersPageActor(CmsRole::Administrator, 'admin@example.com');
    $target = makeTargetUser('target@example.com');

    $response = test()->postJson('/admin/users/actions/userGrantSave', [
        'payload' => ['row' => ['id' => $target->id], 'form' => ['role' => CmsRole::Editor->value]],
    ]);

    $response->assertOk();
    expect(CmsUserProfile::query()->where('user_id', $target->id)->first()?->role)->toBe(CmsRole::Editor);
});

it('rejects an administrator granting the administrator role and makes no DB change', function (): void {
    makeUsersPageActor(CmsRole::Administrator, 'admin@example.com');
    $target = makeTargetUser('target@example.com');

    $response = test()->postJson('/admin/users/actions/userGrantSave', [
        'payload' => ['row' => ['id' => $target->id], 'form' => ['role' => CmsRole::Administrator->value]],
    ]);

    $response->assertOk();
    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('haltModal');
    expect(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeFalse();
});

it('rejects an administrator changing or revoking an administrator row', function (): void {
    makeUsersPageActor(CmsRole::Administrator, 'admin@example.com');
    $target = makeTargetUser('other-admin@example.com', CmsRole::Administrator);

    $changeResponse = test()->postJson('/admin/users/actions/userChangeRoleSave', [
        'payload' => ['row' => ['id' => $target->id], 'form' => ['role' => CmsRole::Editor->value]],
    ]);
    $changeResponse->assertOk();
    expect(collect($changeResponse->json('effects'))->pluck('kind'))->toContain('haltModal');
    expect(CmsUserProfile::query()->where('user_id', $target->id)->first()?->role)->toBe(CmsRole::Administrator);

    $revokeResponse = test()->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $target->id]],
    ]);
    $revokeResponse->assertOk();
    expect(collect($revokeResponse->json('effects'))->pluck('kind'))->not->toContain('refreshTable');
    expect(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeTrue();
});

it('rejects self-demotion via change-role for every role', function (CmsRole $role): void {
    $actor = makeUsersPageActor($role, 'self@example.com');

    $response = test()->postJson('/admin/users/actions/userChangeRoleSave', [
        'payload' => ['row' => ['id' => $actor->id], 'form' => ['role' => CmsRole::Editor->value]],
    ]);

    $response->assertOk();
    expect(collect($response->json('effects'))->pluck('kind'))->toContain('haltModal');
    expect(CmsUserProfile::query()->where('user_id', $actor->id)->first()?->role)->toBe($role);
})->with([
    'superadministrator' => [CmsRole::SuperAdministrator],
    'administrator' => [CmsRole::Administrator],
]);

it('rejects self-revoke for every role', function (CmsRole $role): void {
    $actor = makeUsersPageActor($role, 'self@example.com');

    $response = test()->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $actor->id]],
    ]);

    $response->assertOk();
    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->not->toContain('refreshTable');
    expect(CmsUserProfile::query()->where('user_id', $actor->id)->exists())->toBeTrue();
})->with([
    'superadministrator' => [CmsRole::SuperAdministrator],
    'administrator' => [CmsRole::Administrator],
]);

it('lets a superadministrator revoke any role, deleting the profile row', function (CmsRole $targetRole): void {
    makeUsersPageActor(CmsRole::SuperAdministrator, 'super@example.com');
    $target = makeTargetUser('target@example.com', $targetRole);

    $response = test()->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $target->id]],
    ]);

    $response->assertOk();
    expect(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeFalse();
})->with([
    'editor' => [CmsRole::Editor],
    'administrator' => [CmsRole::Administrator],
    'superadministrator' => [CmsRole::SuperAdministrator],
]);

it('lets an administrator revoke an editor, deleting the profile row', function (): void {
    makeUsersPageActor(CmsRole::Administrator, 'admin@example.com');
    $target = makeTargetUser('editor@example.com', CmsRole::Editor);

    $response = test()->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $target->id]],
    ]);

    $response->assertOk();
    expect(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeFalse();
});

it('lets a superadministrator change any role to any other role', function (): void {
    makeUsersPageActor(CmsRole::SuperAdministrator, 'super@example.com');
    $target = makeTargetUser('target@example.com', CmsRole::Administrator);

    $response = test()->postJson('/admin/users/actions/userChangeRoleSave', [
        'payload' => ['row' => ['id' => $target->id], 'form' => ['role' => CmsRole::SuperAdministrator->value]],
    ]);

    $response->assertOk();
    expect(CmsUserProfile::query()->where('user_id', $target->id)->first()?->role)->toBe(CmsRole::SuperAdministrator);
});
