<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Tests\Fixtures\ApiUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
    $this->user = ApiUser::create(['name' => 'Tracker', 'email' => 'tracker@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $this->user->id]);
    $category = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Services']]);
    $this->lenses = Page::factory()->article()->create(['parent_id' => $category->id, 'status' => PageStatus::Published, 'name' => ['en' => 'Lenses']]);
    $this->draft = Page::factory()->article()->create(['parent_id' => $category->id, 'status' => PageStatus::Draft, 'name' => ['en' => 'Coming soon']]);
});

/** @param  array<string, string>  $options  `cms:api-token` options. */
function issueSiteToken(array $options): string
{
    expect(Artisan::call('cms:api-token', ['user' => 'tracker@example.com', ...$options]))->toBe(0);
    // The guard keeps the first request's user, so every request after a second token needs a fresh guard.
    app('auth')->forgetGuards();
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    return (string) end($lines);
}

const SITE_ALL = 'notfound:read,notfound:write,redirects:read,redirects:write';

it('redirect target must be a published page of the site', function (): void {
    config(['tbtop-cms.redirects.allowed_external_hosts' => ['example.org']]);
    $token = issueSiteToken(['--site' => SITE_ALL]);

    foreach (['https://example.org/lenses', 'services/coming-soon', 'services/nothing-here'] as $target) {
        $this->withToken($token)
            ->postJson('/api/cms/v1/redirects', ['from_path' => 'old-lenses', 'to_path' => $target, 'locale' => 'en'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to_path');
    }

    expect(Redirect::query()->count())->toBe(0);
    $this->get('/old-lenses', ['X-Inertia' => 'true'])->assertNotFound();
});

it('token deletes only its own redirects', function (): void {
    $own = issueSiteToken(['--site' => SITE_ALL]);
    $other = issueSiteToken(['--site' => SITE_ALL, '--name' => 'other']);
    $create = fn (string $token, string $from): int => $this->withToken($token)
        ->postJson('/api/cms/v1/redirects', ['from_path' => $from, 'to_path' => 'services/lenses', 'locale' => 'en'])
        ->assertCreated()->json('id');
    $mine = $create($own, 'old-a');
    app('auth')->forgetGuards();
    $theirs = $create($other, 'old-b');
    $manual = Redirect::create(['locale' => 'en', 'from_path' => 'old-c', 'to_path' => 'services/lenses', 'status_code' => 301]);
    $manual->forceFill(['created_by' => $this->user->id])->save();
    $auto = Redirect::create(['locale' => 'en', 'from_path' => 'old-d', 'to_path' => 'services/lenses', 'status_code' => 301, 'is_auto' => true]);

    app('auth')->forgetGuards();
    foreach ([$theirs, $manual->id, $auto->id] as $id) {
        $this->withToken($own)->deleteJson("/api/cms/v1/redirects/{$id}")->assertForbidden();
    }
    $this->withToken($own)->deleteJson('/api/cms/v1/redirects/999999')->assertNotFound();
    $this->withToken($own)->deleteJson("/api/cms/v1/redirects/{$mine}")->assertNoContent();

    expect(Redirect::query()->pluck('id')->all())->toEqualCanonicalizing([$theirs, $manual->id, $auto->id]);
});

it('legacy write gets no site rights', function (): void {
    $legacy = issueSiteToken(['--pages' => (string) $this->lenses->id]);
    $wildcard = $this->user->createToken('wildcard', ['*'])->plainTextToken;
    $body = ['from_path' => 'old', 'to_path' => 'services/lenses', 'locale' => 'en'];

    foreach ([$legacy, $wildcard] as $token) {
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/cms/v1/not-found')->assertForbidden();
        $this->withToken($token)->postJson('/api/cms/v1/redirects', $body)->assertForbidden();
    }

    expect(Redirect::query()->count())->toBe(0);
});

it('created redirect answers and marks the hit', function (): void {
    $token = issueSiteToken(['--site' => SITE_ALL]);
    $hit = NotFoundHit::create([
        'locale' => 'en', 'path' => 'old-lenses', 'path_hash' => hash('sha256', 'old-lenses'), 'hits' => 3,
        'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
    ]);

    $this->withToken($token)
        ->postJson('/api/cms/v1/redirects', ['from_path' => '/old-lenses/', 'to_path' => '/services/lenses', 'locale' => 'en'])
        ->assertCreated()
        ->assertJsonPath('from_path', 'old-lenses')
        ->assertJsonPath('to_path', 'services/lenses')
        ->assertJsonPath('status_code', 301)
        ->assertJsonPath('is_own', true);

    $this->get('/old-lenses')->assertStatus(301)->assertRedirect('/services/lenses');
    $this->withToken($token)->getJson('/api/cms/v1/not-found?state=all')
        ->assertOk()
        ->assertJsonPath('data.0.id', $hit->id)
        ->assertJsonPath('data.0.has_redirect', true)
        ->assertJsonPath('data.0.is_ignored', true);
});

it('site routes need the redirects role and page routes the pages role', function (): void {
    config(['tbtop-cms.roles' => true]);
    $granted = [];
    foreach (['tbtop-cms.pages', 'tbtop-cms.redirects'] as $role) {
        Gate::define($role, static function () use (&$granted, $role): bool {
            return in_array($role, $granted, true);
        });
    }
    $token = issueSiteToken(['--site' => SITE_ALL, '--pages' => (string) $this->lenses->id, '--actions' => 'read']);
    $page = '/api/cms/v1/pages/' . $this->lenses->id;

    $granted = ['tbtop-cms.pages'];
    $this->withToken($token)->getJson($page)->assertOk();
    $this->withToken($token)->getJson('/api/cms/v1/not-found')->assertForbidden();
    $this->withToken($token)->getJson('/api/cms/v1/redirects')->assertForbidden();

    $granted = ['tbtop-cms.redirects'];
    $this->withToken($token)->getJson($page)->assertForbidden();
    $this->withToken($token)->getJson('/api/cms/v1/not-found')->assertOk();
    $this->withToken($token)->getJson('/api/cms/v1/redirects')->assertOk();
});
