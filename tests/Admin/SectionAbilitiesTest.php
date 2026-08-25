<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Events\CmsAccessGranted;
use Tbtop\Cms\Admin\Events\CmsAccessRevoked;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Support\AdminAccess;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function actingAsSectionAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->create(['user_id' => $user->id]);
    test()->actingAs($user, 'web');

    return $user;
}

it('opens every section to anyone with admin access when roles are off', function (string $path): void {
    config(['tbtop-cms.roles' => false]);
    actingAsSectionAdmin();

    // X-Inertia: a JSON response, so the blade root view (and its @vite,
    // which has no built manifest on CI) never renders — same as the other
    // admin page tests.
    test()->get('/admin/' . $path, ['X-Inertia' => 'true'])->assertOk();
})->with(['pages', 'media-library', 'form-submissions', 'redirects', 'not-found', 'languages', 'users']);

it('hands the section ability to the host when roles are on', function (): void {
    config(['tbtop-cms.roles' => true]);
    Gate::define('tbtop-cms.pages', static fn (): bool => true);
    Gate::define('tbtop-cms.redirects', static fn (): bool => false);
    actingAsSectionAdmin();

    test()->get('/admin/pages', ['X-Inertia' => 'true'])->assertOk();
    test()->get('/admin/redirects', ['X-Inertia' => 'true'])->assertForbidden();
});

// The package defines no section gates, so an ability the host has not
// answered denies rather than silently passing.
it('denies a section the host left unanswered when roles are on', function (): void {
    config(['tbtop-cms.roles' => true]);
    actingAsSectionAdmin();

    test()->get('/admin/pages', ['X-Inertia' => 'true'])->assertForbidden();
});

it('keeps admin access independent of the roles flag', function (bool $roles): void {
    config(['tbtop-cms.roles' => $roles]);
    $stranger = AdminUser::create(['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'secret']);

    test()->actingAs($stranger, 'web')->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertForbidden();
})->with([
    'roles off' => [false],
    'roles on' => [true],
]);

// Drift guard (agreed addition 4): every ability an admin page's can() can
// return under roles-on must be listed in CmsAbilities::ALL, so a host or a
// roles-extension package enumerating the const never misses a gate.
it('lists every page ability in CmsAbilities::ALL', function (): void {
    config(['tbtop-cms.roles' => true]);

    /** @var array<string, class-string<AdminPage>> $pages */
    $pages = config('tbtop-cms.admin.pages', []);

    $pageAbilities = array_unique(array_filter(array_map(
        static fn (string $page): ?string => $page::can(),
        array_values($pages),
    )));

    expect($pageAbilities)->each->toBeIn(CmsAbilities::ALL);
    expect(CmsAbilities::ALL)->each->toBeIn($pageAbilities);
});

// Grant/revoke domain events (agreed addition 5): dispatched exactly once on
// an actual change, never on a no-op, and never through the content-cache
// path — access is not frontend content.
it('dispatches CmsAccessGranted only when granting actually creates a profile row', function (): void {
    $target = AdminUser::create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'secret']);
    Event::fake([CmsAccessGranted::class]);

    AdminAccess::grant($target->id);
    Event::assertDispatched(CmsAccessGranted::class, fn (CmsAccessGranted $e): bool => $e->userId === $target->id);

    Event::fake([CmsAccessGranted::class]);
    AdminAccess::grant($target->id);
    Event::assertNotDispatched(CmsAccessGranted::class);
});

it('rolls back a grant when its event listener fails and can retry it', function (): void {
    $target = AdminUser::create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'secret']);
    $shouldFail = true;
    $handled = 0;

    Event::listen(CmsAccessGranted::class, function () use (&$shouldFail, &$handled): void {
        if ($shouldFail) {
            throw new RuntimeException('Listener failed.');
        }

        $handled++;
    });

    expect(fn () => AdminAccess::grant($target->id))->toThrow(RuntimeException::class)
        ->and(CmsUserProfile::query()->where('user_id', $target->id)->exists())->toBeFalse();

    $shouldFail = false;
    AdminAccess::grant($target->id);

    expect(CmsUserProfile::query()->where('user_id', $target->id)->count())->toBe(1)
        ->and($handled)->toBe(1);
});

it('dispatches CmsAccessRevoked only when revoking actually deletes a profile row', function (): void {
    $target = AdminUser::create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->create(['user_id' => $target->id]);
    Event::fake([CmsAccessRevoked::class]);

    AdminAccess::revoke($target->id);
    Event::assertDispatched(CmsAccessRevoked::class, fn (CmsAccessRevoked $e): bool => $e->userId === $target->id);

    Event::fake([CmsAccessRevoked::class]);
    AdminAccess::revoke($target->id);
    Event::assertNotDispatched(CmsAccessRevoked::class);
});
