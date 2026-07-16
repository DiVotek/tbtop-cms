<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\Support\PageSlugWriter;
use Tbtop\Cms\Redirect\Models\Redirect;

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

    $this->get('/' . $article->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.breadcrumbs')
        ->assertJsonPath('props.breadcrumbs.0.label', 'Blog')
        ->assertJsonPath('props.breadcrumbs.0.url', '/blog');
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

    $this->get('/uk/' . $article->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.breadcrumbs.0.label', 'Blog UA')
        ->assertJsonPath('props.breadcrumbs.0.url', '/uk/' . $category->pathFor('uk'));
});

it('uses an explicit slug override for the first write instead of deriving from the name', function (): void {
    $page = Page::factory()->make(['status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);
    $page->pendingSlugOverrides = ['en' => 'custom-slug'];
    $page->save();

    expect($page->pathFor('en'))->toBe('custom-slug');
});

it('ignores an empty slug override and falls back to deriving the slug from the name', function (): void {
    $page = Page::factory()->make(['status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);
    $page->pendingSlugOverrides = ['en' => ''];
    $page->save();

    expect($page->pathFor('en'))->toBe('about-us');
});

it('caps an overly long slug', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => str_repeat('word ', 60)],
    ]);

    expect(strlen($page->pathFor('en')))->toBeLessThanOrEqual(120);
});

it('refreshes the cached path on the same instance after reparent', function (): void {
    $root = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Root']]);
    $child = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Child']]);

    expect($child->pathFor('en'))->toBe('child');

    $child->update(['parent_id' => $root->id]);

    expect($child->pathFor('en'))->toBe('root/child');
});

it('lets an explicit slug override win on an existing row and records an auto redirect', function (): void {
    $writer = app(PageSlugWriter::class);
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);
    expect($page->pathFor('en'))->toBe('about-us');

    $writer->sync($page, ['en' => 'new-slug']);

    expect($page->fresh()->pathFor('en'))->toBe('new-slug');

    $redirect = Redirect::query()->where('locale', 'en')->where('from_path', 'about-us')->first();

    expect($redirect)->not->toBeNull()
        ->and($redirect->to_path)->toBe('new-slug')
        ->and($redirect->status_code)->toBe(301)
        ->and($redirect->is_auto)->toBeTrue();
});

it('leaves the slug and path unchanged for an empty override on an existing row', function (): void {
    $writer = app(PageSlugWriter::class);
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);

    $writer->sync($page, ['en' => '']);

    expect($page->fresh()->pathFor('en'))->toBe('about-us')
        ->and(Redirect::query()->where('locale', 'en')->exists())->toBeFalse();
});

it('is a no-op when the override matches the current slug', function (): void {
    $writer = app(PageSlugWriter::class);
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);

    $writer->sync($page, ['en' => 'about-us']);

    expect($page->fresh()->pathFor('en'))->toBe('about-us')
        ->and(Redirect::query()->where('locale', 'en')->exists())->toBeFalse();
});

it('suffixes an override that collides with another page path in the same locale', function (): void {
    $writer = app(PageSlugWriter::class);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Taken']]);
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);

    $writer->sync($page, ['en' => 'taken']);

    expect($page->fresh()->pathFor('en'))->toBe('taken-2');
});

it('cascades a parent slug override to children paths and redirects', function (): void {
    $writer = app(PageSlugWriter::class);
    $parent = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Blog']]);
    $child = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $parent->id,
        'name' => ['en' => 'First Post'],
    ]);

    expect($child->pathFor('en'))->toBe('blog/first-post');

    $writer->sync($parent, ['en' => 'news']);

    expect($parent->fresh()->pathFor('en'))->toBe('news')
        ->and($child->fresh()->pathFor('en'))->toBe('news/first-post');

    $redirect = Redirect::query()->where('locale', 'en')->where('from_path', 'blog/first-post')->first();

    expect($redirect)->not->toBeNull()
        ->and($redirect->to_path)->toBe('news/first-post')
        ->and($redirect->is_auto)->toBeTrue();
});

it('ignores a slug override for a system page and leaves its slug/path unchanged', function (): void {
    $writer = app(PageSlugWriter::class);
    $system = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Home'], 'is_system' => true]);
    expect($system->pathFor('en'))->toBe('');

    $writer->sync($system, ['en' => 'home']);

    expect($system->fresh()->pathFor('en'))->toBe('')
        ->and(Redirect::query()->where('locale', 'en')->exists())->toBeFalse();
});

it('applies overrides per locale, leaving other locales untouched', function (): void {
    Language::factory()->create(['locale' => 'uk']);

    $writer = app(PageSlugWriter::class);
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About Us', 'uk' => 'Pro nas']]);

    expect($page->pathFor('en'))->toBe('about-us')
        ->and($page->pathFor('uk'))->toBe('pro-nas');

    $writer->sync($page, ['en' => 'new-slug']);

    expect($page->fresh()->pathFor('en'))->toBe('new-slug')
        ->and($page->fresh()->pathFor('uk'))->toBe('pro-nas');
});
