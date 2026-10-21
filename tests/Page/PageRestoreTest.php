<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('re-creates the page slug on restore so the page is reachable again', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    expect($page->pathFor('en'))->toBe('about');

    $page->delete();
    expect(PageSlug::query()->where('page_id', $page->id)->count())->toBe(0);

    $page->restore();

    expect($page->fresh()->pathFor('en'))->toBe('about');
});

it('suffixes the restored slug when another page took it while trashed', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $page->delete();

    $usurper = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    expect($usurper->pathFor('en'))->toBe('about');

    $page->restore();

    expect($page->fresh()->pathFor('en'))->toBe('about-2')
        ->and($usurper->fresh()->pathFor('en'))->toBe('about');
});

it('blocks restoring a child whose parent is still trashed', function (): void {
    $category = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Blog']]);
    $article = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $category->id,
        'name' => ['en' => 'Post'],
    ]);

    $article->delete();
    $category->delete();

    expect(fn () => $article->restore())->toThrow(DomainException::class)
        ->and($article->fresh()->trashed())->toBeTrue();
});

it('allows restoring a page whose parent is active', function (): void {
    $category = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Blog']]);
    $article = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $category->id,
        'name' => ['en' => 'Post'],
    ]);

    $article->delete();
    $article->restore();

    expect($article->fresh()->trashed())->toBeFalse()
        ->and($article->fresh()->pathFor('en'))->toBe('blog/post');
});

it('allows restoring a root page (no parent) unconditionally', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $page->delete();

    $page->restore();

    expect($page->fresh()->trashed())->toBeFalse();
});

it('does not re-create slugs when a trashed page is saved', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $page->delete();

    $page->content = [['id' => 'b1', 'kind' => 'Hero', 'data' => ['title' => 'Hi']]];
    $page->save();

    expect(PageSlug::query()->where('page_id', $page->id)->count())->toBe(0);

    $other = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    expect($other->pathFor('en'))->toBe('about');
});
