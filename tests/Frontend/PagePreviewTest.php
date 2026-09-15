<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\PreviewLink;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

function makePreviewAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

it('404s a draft page for an anonymous visitor', function (): void {
    Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'Secret']]);

    test()->getJson('/secret', ['X-Inertia' => 'true'])->assertNotFound();
});

it('404s a draft for an authenticated user without cms admin access', function (): void {
    // Authenticated on the panel's guard but with no CmsUserProfile row —
    // e.g. a regular end user of a host app whose `web` guard doubles as
    // the cms admin guard. Guard authentication alone must NOT grant
    // draft visibility.
    $user = AdminUser::create(['name' => 'Visitor', 'email' => 'visitor@example.com', 'password' => 'secret']);
    Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'Secret']]);

    test()->actingAs($user, 'web')->getJson('/secret', ['X-Inertia' => 'true'])->assertNotFound();
});

it('shows a draft page to an authenticated admin with preview props set', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'Secret']]);

    test()->actingAs(makePreviewAdmin(), 'web')
        ->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.isPreview', true)
        ->assertJsonPath('props.seo.noindex', true);
});

it('shows a draft page to any user with a profile row — preview needs access, nothing more', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'Secret']]);
    $editor = AdminUser::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $editor->id]);

    test()->actingAs($editor, 'web')
        ->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.isPreview', true);
});

it('does not force noindex or preview on a published page for an anonymous visitor', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'is_indexable' => true,
    ]);

    test()->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.isPreview', false)
        ->assertJsonPath('props.seo.noindex', false);
});

it('serves a draft with a nested path to an anonymous visitor via a signed preview link', function (): void {
    $category = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Blog']]);
    $sub = Page::factory()->category()->create([
        'status' => PageStatus::Published,
        'parent_id' => $category->id,
        'name' => ['en' => 'Tutorials'],
    ]);
    $page = Page::factory()->article()->create([
        'status' => PageStatus::Draft,
        'parent_id' => $sub->id,
        'name' => ['en' => 'Post'],
    ]);

    expect($page->pathFor('en'))->toBe('blog/tutorials/post');

    $url = app(PreviewLink::class)->for($page, 'en');

    test()->get($url, ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.isPreview', true);
});

it('builds a locale-prefixed signed link for a non-default locale draft', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'status' => PageStatus::Draft,
        'name' => ['en' => 'Secret', 'uk' => 'Секрет'],
    ]);

    $url = app(PreviewLink::class)->for($page, 'uk');

    expect(parse_url($url, PHP_URL_PATH))->toBe('/uk/' . $page->slugs()->where('locale', 'uk')->value('slug'));

    test()->get($url, ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.isPreview', true);
});

it('404s a tampered or expired signed preview link', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'Secret']]);

    $url = app(PreviewLink::class)->for($page, 'en');

    test()->getJson($url . 'x', ['X-Inertia' => 'true'])->assertNotFound();

    test()->travel(73)->hours();
    test()->getJson($url, ['X-Inertia' => 'true'])->assertNotFound();
});

it('serves a signed link for an already-published page as a normal (non-preview) view', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'is_indexable' => true,
    ]);

    $url = app(PreviewLink::class)->for($page, 'en');

    test()->get($url, ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.isPreview', false);
});
