<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeUsersPageActor(string $email): AdminUser
{
    $user = AdminUser::create(['name' => 'Actor', 'email' => $email, 'password' => 'secret']);
    CmsUserProfile::query()->create(['user_id' => $user->id]);
    test()->actingAs($user, 'web');

    return $user;
}

function makeTargetUser(string $email, bool $withAccess = false): AdminUser
{
    $user = AdminUser::create(['name' => 'Target', 'email' => $email, 'password' => 'secret']);
    if ($withAccess) {
        CmsUserProfile::query()->create(['user_id' => $user->id]);
    }

    return $user;
}

it('grants access to a user who has none', function (): void {
    makeUsersPageActor('actor@example.com');
    $target = makeTargetUser('target@example.com');

    $response = test()->postJson('/admin/users/actions/userGrant', [
        'payload' => ['row' => ['id' => $target->id]],
    ]);

    $response->assertOk();
    expect(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeTrue();
});

it('rejects granting access to a user who already has it', function (): void {
    makeUsersPageActor('actor@example.com');
    $target = makeTargetUser('target@example.com', withAccess: true);

    $response = test()->postJson('/admin/users/actions/userGrant', [
        'payload' => ['row' => ['id' => $target->id]],
    ]);

    $response->assertOk();
    expect(collect($response->json('effects'))->pluck('kind'))->not->toContain('refreshTable');
    expect(CmsUserProfile::query()->where('user_id', $target->id)->count())->toBe(1);
});

it('revokes access, deleting the profile row', function (): void {
    makeUsersPageActor('actor@example.com');
    $target = makeTargetUser('target@example.com', withAccess: true);

    $response = test()->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $target->id]],
    ]);

    $response->assertOk();
    expect(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeFalse();
});

it('rejects revoking access from a user who has none', function (): void {
    makeUsersPageActor('actor@example.com');
    $target = makeTargetUser('target@example.com');

    $response = test()->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $target->id]],
    ]);

    $response->assertOk();
    expect(collect($response->json('effects'))->pluck('kind'))->not->toContain('refreshTable');
});

it('rejects self-revoke, keeping the actor row', function (): void {
    $actor = makeUsersPageActor('self@example.com');

    $response = test()->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $actor->id]],
    ]);

    $response->assertOk();
    expect(collect($response->json('effects'))->pluck('kind'))->not->toContain('refreshTable');
    expect(CmsUserProfile::query()->where('user_id', $actor->id)->exists())->toBeTrue();
});

// Keys are compared as strings so uuid/ulid hosts work: an (int) cast would
// read "1-2" as the actor's own key 1 and answer "cannot manage self" for a
// row that is not the actor at all.
it('does not treat a string id sharing the actor numeric prefix as the actor', function (): void {
    $actor = makeUsersPageActor('actor@example.com');

    $response = test()->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $actor->id . '-2']],
    ]);

    $response->assertOk();
    expect(collect($response->json('effects'))->firstWhere('kind', 'notify')['message'])
        ->toBe(__('tbtop-cms::users.validation.no_access'));
});
