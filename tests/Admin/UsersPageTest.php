<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tbtop\Cms\Admin\Events\CmsAccessGranted;
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

it('creates a user, grants access, defaults the name to the email, and sets a verifiable password', function (): void {
    makeUsersPageActor('actor@example.com');
    Event::fake([CmsAccessGranted::class]);

    $response = test()->postJson('/admin/users/actions/userCreateSave', [
        'payload' => ['form' => [
            'email' => 'new@example.com',
            'name' => '',
            'password' => 'password123',
        ]],
    ]);

    $response->assertOk();
    $user = AdminUser::where('email', 'new@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('new@example.com')
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and(CmsUserProfile::where('user_id', $user->id)->exists())->toBeTrue();
    Event::assertDispatched(CmsAccessGranted::class, fn (CmsAccessGranted $e): bool => $e->userId === $user->id);
});

it('rejects creating a user with a duplicate email and creates no user', function (): void {
    makeUsersPageActor('actor@example.com');
    makeTargetUser('taken@example.com');

    $response = test()->postJson('/admin/users/actions/userCreateSave', [
        'payload' => ['form' => [
            'email' => 'taken@example.com',
            'name' => 'Someone',
            'password' => 'password123',
        ]],
    ]);

    $response->assertOk();
    $effects = $response->json('effects');

    expect($effects)->toHaveCount(1)
        ->and($effects[0]['kind'])->toBe('haltModal')
        ->and(AdminUser::where('email', 'taken@example.com')->count())->toBe(1);
});

it('rejects creating a user with a short password and creates no user', function (): void {
    makeUsersPageActor('actor@example.com');

    $response = test()->postJson('/admin/users/actions/userCreateSave', [
        'payload' => ['form' => [
            'email' => 'short@example.com',
            'name' => 'Someone',
            'password' => 'short',
        ]],
    ]);

    $response->assertOk();
    $effects = $response->json('effects');

    expect($effects)->toHaveCount(1)
        ->and($effects[0]['kind'])->toBe('haltModal')
        ->and(AdminUser::where('email', 'short@example.com')->exists())->toBeFalse();
});

it('edits a user, renaming and changing email, leaving the password unchanged when blank', function (): void {
    makeUsersPageActor('actor@example.com');
    $target = makeTargetUser('target@example.com', withAccess: true);
    $originalPassword = $target->password;

    $response = test()->postJson('/admin/users/actions/userEditSave', [
        'payload' => [
            'row' => ['id' => $target->id],
            'form' => ['name' => 'Renamed', 'email' => 'renamed@example.com', 'password' => ''],
        ],
    ]);

    $response->assertOk();
    $target->refresh();

    expect($target->name)->toBe('Renamed')
        ->and($target->email)->toBe('renamed@example.com')
        ->and($target->password)->toBe($originalPassword)
        ->and(Hash::check('secret', $target->password))->toBeTrue();
});

it('changes the password on edit when a new one is submitted', function (): void {
    makeUsersPageActor('actor@example.com');
    $target = makeTargetUser('target@example.com', withAccess: true);

    $response = test()->postJson('/admin/users/actions/userEditSave', [
        'payload' => [
            'row' => ['id' => $target->id],
            'form' => ['name' => $target->name, 'email' => $target->email, 'password' => 'newpassword123'],
        ],
    ]);

    $response->assertOk();
    $target->refresh();

    expect(Hash::check('newpassword123', $target->password))->toBeTrue();
});

it('rejects editing a user into a duplicate email of another user', function (): void {
    makeUsersPageActor('actor@example.com');
    makeTargetUser('taken@example.com');
    $target = makeTargetUser('target@example.com', withAccess: true);

    $response = test()->postJson('/admin/users/actions/userEditSave', [
        'payload' => [
            'row' => ['id' => $target->id],
            'form' => ['name' => $target->name, 'email' => 'taken@example.com', 'password' => ''],
        ],
    ]);

    $response->assertOk();
    $effects = $response->json('effects');

    expect($effects)->toHaveCount(1)
        ->and($effects[0]['kind'])->toBe('haltModal')
        ->and($target->fresh()->email)->toBe('target@example.com');
});

it('allows an admin to edit their own row', function (): void {
    $actor = makeUsersPageActor('actor@example.com');

    $response = test()->postJson('/admin/users/actions/userEditSave', [
        'payload' => [
            'row' => ['id' => $actor->id],
            'form' => ['name' => 'New Name', 'email' => $actor->email, 'password' => ''],
        ],
    ]);

    $response->assertOk();
    expect($actor->fresh()->name)->toBe('New Name');
});

// The form marks name required, but only the server assert protects against
// a direct action request that bypasses the client-side check.
it('rejects editing a user to a blank name and keeps the current one', function (): void {
    makeUsersPageActor('actor@example.com');
    $target = makeTargetUser('target@example.com', withAccess: true);

    $response = test()->postJson('/admin/users/actions/userEditSave', [
        'payload' => [
            'row' => ['id' => $target->id],
            'form' => ['name' => '   ', 'email' => $target->email, 'password' => ''],
        ],
    ]);

    $response->assertOk();
    $effects = $response->json('effects');

    expect($effects)->toHaveCount(1)
        ->and($effects[0]['kind'])->toBe('haltModal')
        ->and($target->fresh()->name)->toBe('Target');
});
