<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tbtop\Cms\Admin\Events\CmsAccessRevoked;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Support\AdminAccess;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

it('marks the first-ever grant as owner but not the second', function (): void {
    $first = AdminUser::create(['name' => 'First', 'email' => 'first@example.com', 'password' => 'secret']);
    $second = AdminUser::create(['name' => 'Second', 'email' => 'second@example.com', 'password' => 'secret']);

    AdminAccess::grant($first->id);
    AdminAccess::grant($second->id);

    expect(AdminAccess::isOwner($first->id))->toBeTrue()
        ->and(AdminAccess::isOwner($second->id))->toBeFalse();
});

// Pins the guard living in the UPDATE statement itself (not a
// check-then-write): rows created outside AdminAccess leave no owner, and
// the next grant self-heals by claiming ownership — while a second owner is
// impossible by construction.
it('marks a grant as owner when rows exist but none is owner yet', function (): void {
    $stray = AdminUser::create(['name' => 'Stray', 'email' => 'stray@example.com', 'password' => 'secret']);
    $next = AdminUser::create(['name' => 'Next', 'email' => 'next@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->create(['user_id' => $stray->id]);

    AdminAccess::grant($next->id);

    expect(AdminAccess::isOwner($next->id))->toBeTrue()
        ->and(CmsUserProfile::query()->where('is_owner', true)->count())->toBe(1);
});

it('never deletes the owner row via AdminAccess::revoke, dispatching no event', function (): void {
    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
    AdminAccess::grant($owner->id);
    Event::fake([CmsAccessRevoked::class]);

    AdminAccess::revoke($owner->id);

    expect(CmsUserProfile::where('user_id', $owner->id)->exists())->toBeTrue();
    Event::assertNotDispatched(CmsAccessRevoked::class);
});

it('rejects revoking the owner from the Users page, keeping the row intact', function (): void {
    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
    AdminAccess::grant($owner->id);
    $actor = AdminUser::create(['name' => 'Actor', 'email' => 'actor@example.com', 'password' => 'secret']);
    AdminAccess::grant($actor->id);
    test()->actingAs($actor, 'web');

    $response = test()->postJson('/admin/users/actions/userRevoke', [
        'payload' => ['row' => ['id' => $owner->id]],
    ]);

    $response->assertOk();
    expect(collect($response->json('effects'))->firstWhere('kind', 'notify')['message'])
        ->toBe(__('tbtop-cms::users.validation.cannot_revoke_owner'))
        ->and(CmsUserProfile::where('user_id', $owner->id)->exists())->toBeTrue();
});

it('rejects editing the owner by another admin, leaving their data intact', function (): void {
    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
    AdminAccess::grant($owner->id);
    $actor = AdminUser::create(['name' => 'Actor', 'email' => 'actor@example.com', 'password' => 'secret']);
    AdminAccess::grant($actor->id);
    test()->actingAs($actor, 'web');

    $response = test()->postJson('/admin/users/actions/userEditSave', [
        'payload' => [
            'row' => ['id' => $owner->id],
            'form' => ['name' => 'Hijacked', 'email' => 'hijacked@example.com', 'password' => ''],
        ],
    ]);

    $response->assertOk();
    $effects = $response->json('effects');

    expect($effects)->toHaveCount(1)
        ->and($effects[0]['kind'])->toBe('haltModal')
        ->and($owner->fresh()->name)->toBe('Owner')
        ->and($owner->fresh()->email)->toBe('owner@example.com');
});

it('allows the owner to edit their own row', function (): void {
    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
    AdminAccess::grant($owner->id);
    test()->actingAs($owner, 'web');

    $response = test()->postJson('/admin/users/actions/userEditSave', [
        'payload' => [
            'row' => ['id' => $owner->id],
            'form' => ['name' => 'Owner Renamed', 'email' => 'owner@example.com', 'password' => ''],
        ],
    ]);

    $response->assertOk();
    expect($owner->fresh()->name)->toBe('Owner Renamed');
});
