<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Support\AdminAccess;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\GatedContractAdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

/**
 * Owner first (AdminAccess marks the first grant), then the actor and the
 * target as plain admins — so neither of them is the owner unless asked.
 *
 * @return array{owner: AdminUser, actor: AdminUser, target: AdminUser}
 */
function adminsForPermissions(bool $targetHasAccess = true): array
{
    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
    AdminAccess::grant($owner->id);
    $actor = AdminUser::create(['name' => 'Actor', 'email' => 'actor@example.com', 'password' => 'secret']);
    AdminAccess::grant($actor->id);
    $target = AdminUser::create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'secret']);
    if ($targetHasAccess) {
        AdminAccess::grant($target->id);
    }

    return ['owner' => $owner, 'actor' => $actor, 'target' => $target];
}

function editUserAs(AdminUser | GatedContractAdminUser $actor, int $targetId): array
{
    return test()->actingAs($actor, 'web')->postJson('/admin/users/actions/userEditSave', [
        'payload' => [
            'row' => ['id' => $targetId],
            'form' => ['name' => 'Renamed', 'email' => 'renamed@example.com', 'password' => 'newpassword123'],
        ],
    ])->assertOk()->json('effects');
}

function revokeUserAs(AdminUser $actor, int $targetId): array
{
    return test()->actingAs($actor, 'web')->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $targetId]],
    ])->assertOk()->json('effects');
}

it('refuses an admin editing another admin, leaving the account intact', function (): void {
    ['actor' => $actor, 'target' => $target] = adminsForPermissions();

    $effects = editUserAs($actor, $target->id);

    expect($effects)->toHaveCount(1)
        ->and($effects[0])->toMatchArray(['kind' => 'haltModal', 'message' => __('tbtop-cms::users.errors.not_allowed')])
        ->and($target->fresh()->email)->toBe('target@example.com')
        ->and(Hash::check('secret', $target->fresh()->password))->toBeTrue();
});

it('refuses an admin revoking another admin, keeping their access', function (): void {
    ['actor' => $actor, 'target' => $target] = adminsForPermissions();

    $effects = revokeUserAs($actor, $target->id);

    expect(collect($effects)->firstWhere('kind', 'notify'))
        ->toMatchArray(['message' => __('tbtop-cms::users.errors.revoke_not_allowed'), 'level' => 'error'])
        ->and(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeTrue();
});

it('refuses opening the edit form of another admin', function (): void {
    ['actor' => $actor, 'target' => $target] = adminsForPermissions();

    test()->actingAs($actor, 'web')->postJson('/admin/users/actions/userEdit/data', [
        'payload' => ['row' => ['id' => $target->id]],
    ])->assertForbidden()->assertJsonPath('message', __('tbtop-cms::users.errors.not_allowed'));
});

it('lets any admin edit a row without CMS access', function (): void {
    ['actor' => $actor, 'target' => $target] = adminsForPermissions(targetHasAccess: false);

    editUserAs($actor, $target->id);

    expect($target->fresh()->email)->toBe('renamed@example.com')
        ->and(Hash::check('newpassword123', $target->fresh()->password))->toBeTrue();
});

it('lets the owner edit and revoke another admin', function (): void {
    ['owner' => $owner, 'target' => $target] = adminsForPermissions();

    editUserAs($owner, $target->id);
    revokeUserAs($owner, $target->id);

    expect($target->fresh()->email)->toBe('renamed@example.com')
        ->and(Hash::check('newpassword123', $target->fresh()->password))->toBeTrue()
        ->and(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeFalse();
});

it('lets a holder of the admins gate edit and revoke another admin when roles are on', function (): void {
    config(['tbtop-cms.roles' => true]);
    Gate::define('tbtop-cms.users', static fn (): bool => true);
    Gate::define('tbtop-cms.admins', static fn (): bool => true);
    ['actor' => $actor, 'target' => $target] = adminsForPermissions();

    editUserAs($actor, $target->id);
    revokeUserAs($actor, $target->id);

    expect($target->fresh()->email)->toBe('renamed@example.com')
        ->and(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeFalse();
});

it('refuses another admin when roles are on but the admins gate is denied', function (): void {
    config(['tbtop-cms.roles' => true]);
    Gate::define('tbtop-cms.users', static fn (): bool => true);
    Gate::define('tbtop-cms.admins', static fn (): bool => false);
    ['actor' => $actor, 'target' => $target] = adminsForPermissions();

    $effects = editUserAs($actor, $target->id);

    expect($effects[0]['message'] ?? null)->toBe(__('tbtop-cms::users.errors.not_allowed'))
        ->and($target->fresh()->email)->toBe('target@example.com');
});

it('ignores the admins gate when roles are off', function (): void {
    config(['tbtop-cms.roles' => false]);
    Gate::define('tbtop-cms.admins', static fn (): bool => true);
    ['actor' => $actor, 'target' => $target] = adminsForPermissions();

    $edit = editUserAs($actor, $target->id);
    $revoke = revokeUserAs($actor, $target->id);

    expect($edit[0]['message'] ?? null)->toBe(__('tbtop-cms::users.errors.not_allowed'))
        ->and(collect($revoke)->firstWhere('kind', 'notify')['message'])->toBe(__('tbtop-cms::users.errors.revoke_not_allowed'))
        ->and($target->fresh()->email)->toBe('target@example.com')
        ->and(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeTrue();
});

it('decides the target admin status from canAccessCmsAdmin() in CmsAdmin mode', function (): void {
    config(['tbtop-cms.admin.model' => GatedContractAdminUser::class]);
    config(['auth.providers.users.model' => GatedContractAdminUser::class]);
    $actor = GatedContractAdminUser::query()->create(['name' => 'Actor', 'email' => 'actor@admins.test', 'password' => 'secret']);
    $admin = GatedContractAdminUser::query()->create(['name' => 'Admin', 'email' => 'admin@admins.test', 'password' => 'secret']);
    $stranger = GatedContractAdminUser::query()->create(['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'secret']);

    $refused = editUserAs($actor, $admin->id);
    editUserAs($actor, $stranger->id);

    expect($refused[0]['message'] ?? null)->toBe(__('tbtop-cms::users.errors.not_allowed'))
        ->and($admin->fresh()->email)->toBe('admin@admins.test')
        ->and($stranger->fresh()->email)->toBe('renamed@example.com');
});

it('lets a holder of the admins gate edit another admin in CmsAdmin mode when roles are on', function (): void {
    config(['tbtop-cms.admin.model' => GatedContractAdminUser::class]);
    config(['auth.providers.users.model' => GatedContractAdminUser::class]);
    config(['tbtop-cms.roles' => true]);
    Gate::define('tbtop-cms.users', static fn (): bool => true);
    Gate::define('tbtop-cms.admins', static fn (): bool => true);
    $actor = GatedContractAdminUser::query()->create(['name' => 'Actor', 'email' => 'actor@admins.test', 'password' => 'secret']);
    $admin = GatedContractAdminUser::query()->create(['name' => 'Admin', 'email' => 'admin@admins.test', 'password' => 'secret']);

    editUserAs($actor, $admin->id);

    expect($admin->fresh()->email)->toBe('renamed@example.com');
});
