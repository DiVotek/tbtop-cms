<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('suffixes a colliding sibling slug', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $second = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    expect($second->pathFor('en'))->toBe('about-2');
});

it('builds nested paths from the ancestor chain', function (): void {
    $category = Page::factory()->category()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Blog'],
    ]);
    $article = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $category->id,
        'name' => ['en' => 'First Post'],
    ]);

    expect($article->pathFor('en'))->toBe('blog/first-post');
});

it('derives localized breadcrumbs from ancestors', function (): void {
    $category = Page::factory()->category()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Blog'],
        'title' => ['en' => 'Blog'],
    ]);
    $article = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $category->id,
        'name' => ['en' => 'First Post'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->getJson('/' . $article->pathFor('en'))
        ->assertOk()
        ->assertJsonCount(1, 'breadcrumbs')
        ->assertJsonPath('breadcrumbs.0.label', 'Blog')
        ->assertJsonPath('breadcrumbs.0.url', '/blog');
});

it('rebuilds descendant paths when an ancestor is reparented', function (): void {
    $root = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Root']]);
    $mid = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Mid']]);
    $article = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $mid->id,
        'name' => ['en' => 'Post'],
    ]);

    expect($article->pathFor('en'))->toBe('mid/post');

    $mid->update(['parent_id' => $root->id]);

    expect($article->fresh()->pathFor('en'))->toBe('root/mid/post');
});

it('frees the path when a page is soft-deleted', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Temp']]);
    expect($page->pathFor('en'))->toBe('temp');

    $page->delete();

    expect(PageSlug::query()->where('page_id', $page->id)->count())->toBe(0);

    $reused = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Temp']]);
    expect($reused->pathFor('en'))->toBe('temp');
});

it('backfills slugs for every page when a locale is activated', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    expect(PageSlug::query()->where('page_id', $page->id)->where('locale', 'uk')->exists())->toBeFalse();

    Language::factory()->create(['locale' => 'uk']);

    expect(PageSlug::query()->where('page_id', $page->id)->where('locale', 'uk')->exists())->toBeTrue();
});

it('localizes breadcrumb paths and labels for a non-default locale', function (): void {
    Language::factory()->create(['locale' => 'uk']);

    $category = Page::factory()->category()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Blog', 'uk' => 'Bloh'],
        'title' => ['en' => 'Blog', 'uk' => 'Blog UA'],
    ]);
    $article = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $category->id,
        'name' => ['en' => 'First Post', 'uk' => 'Persha'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->getJson('/uk/' . $article->pathFor('uk'))
        ->assertOk()
        ->assertJsonPath('breadcrumbs.0.label', 'Blog UA')
        ->assertJsonPath('breadcrumbs.0.url', '/uk/' . $category->pathFor('uk'));
});
