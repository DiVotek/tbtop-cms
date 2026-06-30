<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('resolves a default-locale page at a clean URL', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Cms/Page')
        ->assertJsonPath('props.page.id', $page->id)
        ->assertJsonPath('props.cms.locale', 'en');
});

it('resolves a non-default-locale page at a prefixed URL', function (): void {
    Language::factory()->create(['locale' => 'uk']);

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Cms/Page')
        ->assertJsonPath('props.page.id', $page->id)
        ->assertJsonPath('props.cms.locale', 'uk');
});

it('lets a host route win over the CMS catch-all', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Contact'],
    ]);

    Route::get('/contact', fn () => response()->json(['host' => true]));
    app(Cms::class)->frontendRoutes();

    $this->getJson('/contact')
        ->assertOk()
        ->assertJsonPath('host', true);
});

it('resolves the system home page at the empty root path', function (): void {
    $home = Page::factory()->create([
        'status' => PageStatus::Published,
        'is_system' => true,
        'name' => ['en' => 'Home'],
    ]);

    expect($home->pathFor('en'))->toBe('')
        ->and(app(Cms::class)->resolve('', 'en')?->id)->toBe($home->id);
});

it('returns 404 for an unknown path', function (): void {
    app(Cms::class)->frontendRoutes();

    $this->getJson('/missing')->assertNotFound();
});

it('does not resolve a draft page', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Draft,
        'name' => ['en' => 'Secret'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->getJson('/secret')->assertNotFound();
});

it('redirects the default-locale prefix to the clean URL', function (): void {
    app(Cms::class)->frontendRoutes();

    $this->get('/en/about')->assertRedirect('/about')->assertStatus(301);
});

it('redirects the bare default-locale prefix to the site root', function (): void {
    app(Cms::class)->frontendRoutes();

    $this->get('/en')->assertRedirect('/');
});

it('preserves the query string when canonicalizing the locale prefix', function (): void {
    app(Cms::class)->frontendRoutes();

    $this->get('/en/about?ref=x')->assertRedirect('/about?ref=x');
});
