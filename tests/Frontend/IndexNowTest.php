<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;

uses(RefreshDatabase::class);

const INDEXNOW_KEY = '0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Language::factory()->default()->create(['locale' => 'en']);
    Http::preventStrayRequests();
    Http::fake(['https://api.indexnow.org/indexnow' => Http::response(null, 202)]);
});

function indexNowOn(): void
{
    app(Settings::class)->set('seo.indexnow.enabled', true);
    app(Settings::class)->set('seo.indexnow.key', INDEXNOW_KEY);
}

/** What the end of the request does: runs the defer() callbacks. */
function endRequest(): void
{
    app(DeferredCallbackCollection::class)->invoke();
}

/** @return list<array<string, mixed>> */
function indexNowPosts(): array
{
    return Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values()->all();
}

it('serves a generated key file once an editor turns IndexNow on', function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class, 'auth.providers.users.model' => AdminUser::class]);
    app(Cms::class)->frontendRoutes();

    test()->actingAs(PageEditHelpers::admin(), 'web')
        ->post('/admin/seo-settings/forms/seo-settings', ['seo_indexnow_enabled' => true])
        ->assertSessionHasNoErrors();
    $key = app(Settings::class)->get('seo.indexnow.key');

    expect($key)->toMatch('/\A[0-9a-f]{32}\z/');
    $response = $this->get('/' . $key . '.txt');
    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())->toBe($key);
    $this->getJson('/ffffffffffffffffffffffffffffffff.txt')->assertNotFound();
});

it('404s the key file while IndexNow is off or the site is closed to search engines', function (array $settings): void {
    indexNowOn();
    foreach ($settings as $key => $value) {
        app(Settings::class)->set($key, $value);
    }
    app(Cms::class)->frontendRoutes();

    $this->getJson('/' . INDEXNOW_KEY . '.txt')->assertNotFound();
})->with([
    'IndexNow off' => [['seo.indexnow.enabled' => false]],
    'indexing off' => [['seo.indexing_enabled' => false]],
]);

it('submits every locale URL of a page once it is published', function (): void {
    Language::factory()->create(['locale' => 'uk', 'name' => 'Українська']);
    indexNowOn();
    $page = Page::factory()->create(['name' => ['en' => 'About', 'uk' => 'Про нас']]);
    endRequest();

    $page->update(['status' => PageStatus::Published]);
    endRequest();

    expect(indexNowPosts())->toBe([[
        'host' => 'localhost',
        'key' => INDEXNOW_KEY,
        'keyLocation' => 'http://localhost/' . INDEXNOW_KEY . '.txt',
        'urlList' => ['http://localhost/about', 'http://localhost/uk/pro-nas'],
    ]]);
});

it('submits nothing for a page that is not public before or after the save', function (array $attributes): void {
    indexNowOn();
    $page = Page::factory()->create(['name' => ['en' => 'About'], ...$attributes]);
    $page->update(['name' => ['en' => 'About us']]);
    endRequest();

    Http::assertNothingSent();
})->with([
    'draft' => [['status' => PageStatus::Draft]],
    'noindex' => [['status' => PageStatus::Published, 'is_indexable' => false]],
]);

it('submits nothing when a draft is scheduled to go live later', function (): void {
    indexNowOn();
    $page = Page::factory()->create(['name' => ['en' => 'About'], 'status' => PageStatus::Draft]);
    $page->update(['status' => PageStatus::Published, 'published_at' => now()->addDay()]);
    endRequest();

    Http::assertNothingSent();
});

it('submits the URL of a published page that goes noindex or back to draft', function (array $change): void {
    indexNowOn();
    $page = Page::factory()->create(['name' => ['en' => 'About'], 'status' => PageStatus::Published]);
    endRequest();
    $before = count(indexNowPosts());

    $page->update($change);
    endRequest();

    expect(array_slice(indexNowPosts(), $before))->toHaveCount(1)
        ->and(array_slice(indexNowPosts(), $before)[0]['urlList'])->toBe(['http://localhost/about']);
})->with([
    'noindex' => [['is_indexable' => false]],
    'unpublished' => [['status' => PageStatus::Draft]],
]);

it('submits the URL of a published page an editor deletes', function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class, 'auth.providers.users.model' => AdminUser::class]);
    $page = Page::factory()->create(['name' => ['en' => 'About'], 'status' => PageStatus::Published]);
    indexNowOn();

    test()->actingAs(PageEditHelpers::admin(), 'web')
        ->postJson("/admin/pages/{$page->id}/edit/actions/pageEditDelete", ['payload' => []])
        ->assertOk();

    expect($page->fresh()?->trashed())->toBeTrue()
        ->and(indexNowPosts())->toHaveCount(1)
        ->and(indexNowPosts()[0]['urlList'])->toBe(['http://localhost/about']);
});

it('submits nothing while indexing is off', function (): void {
    indexNowOn();
    app(Settings::class)->set('seo.indexing_enabled', false);

    Page::factory()->create(['name' => ['en' => 'About'], 'status' => PageStatus::Published]);
    endRequest();

    Http::assertNothingSent();
});

it('sends one request for every page a request changed, each URL once', function (): void {
    indexNowOn();

    $about = Page::factory()->create(['name' => ['en' => 'About'], 'status' => PageStatus::Published]);
    $about->update(['sort_order' => 3]);
    Page::factory()->create(['name' => ['en' => 'Contacts'], 'status' => PageStatus::Published]);
    endRequest();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['urlList'] === ['http://localhost/about', 'http://localhost/contacts']);
});

it('keeps the save when IndexNow cannot be reached', function (): void {
    Http::fake(['https://api.indexnow.org/indexnow' => Http::failedConnection()]);
    indexNowOn();

    $page = Page::factory()->create(['name' => ['en' => 'About'], 'status' => PageStatus::Published]);
    endRequest();

    expect($page->fresh()?->status)->toBe(PageStatus::Published);
    Http::assertSentCount(1);
});
