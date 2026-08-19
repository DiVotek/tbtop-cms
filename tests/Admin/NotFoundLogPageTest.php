<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function actingAsNotFoundAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    test()->actingAs($user, 'web');

    return $user;
}

/** @return array<string, mixed> First node of the given kind in the page structure. */
function notFoundTableNode(): array
{
    $structure = test()->get('/admin/not-found', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $found = null;
    $walk = function (array $node) use (&$walk, &$found): void {
        if (($node['kind'] ?? null) === 'table') {
            $found ??= $node;

            return;
        }
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            $walk($child);
        }
    };
    $walk($structure);

    expect($found)->not->toBeNull();

    return $found;
}

function makeNotFoundHit(array $overrides = []): NotFoundHit
{
    $path = $overrides['path'] ?? 'missing-page';

    return NotFoundHit::query()->create([
        'locale' => 'en',
        'path' => $path,
        'path_hash' => hash('sha256', $path),
        'hits' => 3,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_ignored' => false,
        ...$overrides,
    ]);
}

it('redirects a guest away from the 404 log', function (): void {
    test()->get('/admin/not-found')->assertRedirect(route('login'));
});

it('lists rows and filters active vs ignored through tabs', function (): void {
    actingAsNotFoundAdmin();

    $node = notFoundTableNode();
    $tabs = array_column($node['options']['tabs'], 'name');

    expect($tabs)->toBe(['active', 'ignored']);
});

it('shows a created hit in the table query results', function (): void {
    actingAsNotFoundAdmin();
    makeNotFoundHit(['path' => 'gone']);

    $response = test()->getJson('/admin/not-found/tables/notFoundHits')->assertOk();

    expect(collect($response->json('data.data'))->pluck('path'))->toContain('gone');
});

it('flags a hit whose path already redirects, for its own locale or any locale', function (): void {
    actingAsNotFoundAdmin();
    makeNotFoundHit(['path' => 'covered-en']);
    makeNotFoundHit(['path' => 'covered-any']);
    makeNotFoundHit(['path' => 'covered-other-locale']);
    makeNotFoundHit(['path' => 'uncovered']);
    Redirect::query()->create(['locale' => 'en', 'from_path' => 'covered-en', 'to_path' => 'x', 'status_code' => 301]);
    Redirect::query()->create(['locale' => null, 'from_path' => 'covered-any', 'to_path' => 'x', 'status_code' => 301]);
    Redirect::query()->create(['locale' => 'de', 'from_path' => 'covered-other-locale', 'to_path' => 'x', 'status_code' => 301]);

    $rows = collect(test()->getJson('/admin/not-found/tables/notFoundHits')->assertOk()->json('data.data'))
        ->mapWithKeys(static fn (array $row): array => [$row['path'] => (bool) $row['has_redirect']]);

    expect($rows->all())->toBe([
        'covered-en' => true,
        'covered-any' => true,
        'covered-other-locale' => false,
        'uncovered' => false,
    ]);
});

it('hides the create-redirect row action when the path already redirects', function (): void {
    actingAsNotFoundAdmin();

    $node = notFoundTableNode();
    $action = collect($node['options']['rowActions'])->firstWhere('name', 'notFoundCreateRedirect');

    expect($action)->not->toBeNull()
        ->and($action['meta']['hiddenIf'] ?? null)->toBe(['op' => 'truthy', 'field' => 'has_redirect']);
});

it('creates a redirect from the row action and marks the hit ignored', function (): void {
    actingAsNotFoundAdmin();
    $hit = makeNotFoundHit(['path' => 'old-broken-link']);

    $response = test()->postJson('/admin/not-found/actions/notFoundCreateRedirectSave', [
        'payload' => [
            'row' => ['id' => $hit->id, 'path' => $hit->path, 'locale' => $hit->locale],
            'form' => ['to_path' => 'new-target', 'locale' => 'en', 'status_code' => '301'],
        ],
    ]);

    $response->assertOk();

    $redirect = Redirect::query()->where('from_path', 'old-broken-link')->first();
    expect($redirect)->not->toBeNull()
        ->and($redirect->to_path)->toBe('new-target');

    expect($hit->fresh()->is_ignored)->toBeTrue();
});

it('toggles a hit to ignored and back via the ignore/unignore actions', function (): void {
    actingAsNotFoundAdmin();
    $hit = makeNotFoundHit();

    test()->postJson('/admin/not-found/actions/notFoundIgnore', [
        'payload' => ['row' => ['id' => $hit->id]],
    ])->assertOk();

    expect($hit->fresh()->is_ignored)->toBeTrue();

    test()->postJson('/admin/not-found/actions/notFoundUnignore', [
        'payload' => ['row' => ['id' => $hit->id]],
    ])->assertOk();

    expect($hit->fresh()->is_ignored)->toBeFalse();
});

it('deletes a row via the delete action', function (): void {
    actingAsNotFoundAdmin();
    $hit = makeNotFoundHit();

    test()->postJson('/admin/not-found/actions/notFoundDelete', [
        'payload' => ['row' => ['id' => $hit->id]],
    ])->assertOk();

    expect(NotFoundHit::query()->count())->toBe(0);
});

it('bulk-ignores the selected rows', function (): void {
    actingAsNotFoundAdmin();
    $first = makeNotFoundHit(['path' => 'bulk-a']);
    $second = makeNotFoundHit(['path' => 'bulk-b']);

    test()->postJson('/admin/not-found/actions/notFoundBulkIgnore', [
        'payload' => ['selection' => [$first->id, $second->id]],
    ])->assertOk();

    expect($first->fresh()->is_ignored)->toBeTrue()
        ->and($second->fresh()->is_ignored)->toBeTrue();
});

it('bulk-deletes the selected rows', function (): void {
    actingAsNotFoundAdmin();
    $first = makeNotFoundHit(['path' => 'bulk-c']);
    $second = makeNotFoundHit(['path' => 'bulk-d']);

    test()->postJson('/admin/not-found/actions/notFoundBulkDelete', [
        'payload' => ['selection' => [$first->id, $second->id]],
    ])->assertOk();

    expect(NotFoundHit::query()->count())->toBe(0);
});
