<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

it('allows a page at the root', function (): void {
    $page = Page::factory()->create(['type' => 'page']);

    expect($page->exists)->toBeTrue();
});

it('rejects a page that is given a parent', function (): void {
    $parent = Page::factory()->category()->create();

    expect(fn () => Page::factory()->create(['type' => 'page', 'parent_id' => $parent->id]))
        ->toThrow(DomainException::class);
});

it('allows a category at the root and nested under a category', function (): void {
    $root = Page::factory()->category()->create();
    $child = Page::factory()->category()->create(['parent_id' => $root->id]);

    expect($child->parent_id)->toBe($root->id);
});

it('requires a parent for an article', function (): void {
    expect(fn () => Page::factory()->article()->create())
        ->toThrow(DomainException::class);
});

it('allows an article under a category', function (): void {
    $category = Page::factory()->category()->create();
    $article = Page::factory()->article()->create(['parent_id' => $category->id]);

    expect($article->parent_id)->toBe($category->id);
});

it('rejects an article under a page', function (): void {
    $page = Page::factory()->create(['type' => 'page']);

    expect(fn () => Page::factory()->article()->create(['parent_id' => $page->id]))
        ->toThrow(DomainException::class);
});

it('rejects a category under an article', function (): void {
    $category = Page::factory()->category()->create();
    $article = Page::factory()->article()->create(['parent_id' => $category->id]);

    expect(fn () => Page::factory()->category()->create(['parent_id' => $article->id]))
        ->toThrow(DomainException::class);
});

it('rejects a parent change that creates a cycle', function (): void {
    $a = Page::factory()->category()->create();
    $b = Page::factory()->category()->create(['parent_id' => $a->id]);

    expect(fn () => $a->update(['parent_id' => $b->id]))
        ->toThrow(DomainException::class);
});

it('validates structural changes inside the write transaction', function (): void {
    $page = Page::factory()->category()->create();
    $parent = Page::factory()->category()->create();
    $levels = [];

    DB::listen(function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    $page->update(['parent_id' => $parent->id]);

    expect($levels)->not->toBeEmpty()
        ->and(min($levels))->toBeGreaterThan(0);
});

it('allows category nesting up to depth 3', function (): void {
    $c1 = Page::factory()->category()->create();
    $c2 = Page::factory()->category()->create(['parent_id' => $c1->id]);
    $c3 = Page::factory()->category()->create(['parent_id' => $c2->id]);

    expect($c3->parent_id)->toBe($c2->id);
});

it('rejects category nesting beyond depth 3', function (): void {
    $c1 = Page::factory()->category()->create();
    $c2 = Page::factory()->category()->create(['parent_id' => $c1->id]);
    $c3 = Page::factory()->category()->create(['parent_id' => $c2->id]);

    expect(fn () => Page::factory()->category()->create(['parent_id' => $c3->id]))
        ->toThrow(DomainException::class);
});

it('allows an article under a depth-3 category', function (): void {
    $c1 = Page::factory()->category()->create();
    $c2 = Page::factory()->category()->create(['parent_id' => $c1->id]);
    $c3 = Page::factory()->category()->create(['parent_id' => $c2->id]);
    $article = Page::factory()->article()->create(['parent_id' => $c3->id]);

    expect($article->parent_id)->toBe($c3->id);
});

it('forbids deleting a page that has children', function (): void {
    $category = Page::factory()->category()->create();
    Page::factory()->article()->create(['parent_id' => $category->id]);

    expect(fn () => $category->delete())->toThrow(DomainException::class);
});

it('forbids deleting a system page', function (): void {
    $page = Page::factory()->create(['type' => 'page', 'is_system' => true]);

    expect(fn () => $page->delete())->toThrow(DomainException::class);
});

it('forbids changing the status of a system page', function (): void {
    $page = Page::factory()->create([
        'type' => 'page',
        'is_system' => true,
        'status' => 'published',
    ]);

    expect(fn () => $page->update(['status' => 'draft']))->toThrow(DomainException::class);
});

it('rejects an unregistered page type', function (): void {
    expect(fn () => Page::factory()->create(['type' => 'widget']))
        ->toThrow(DomainException::class);
});

it('rejects changing a parent type so it no longer allows its children', function (): void {
    $category = Page::factory()->category()->create();
    Page::factory()->article()->create(['parent_id' => $category->id]);

    expect(fn () => $category->update(['type' => 'page']))->toThrow(DomainException::class);
});

it('forbids force-deleting a page whose only children are trashed', function (): void {
    $category = Page::factory()->category()->create();
    $article = Page::factory()->article()->create(['parent_id' => $category->id]);
    $article->delete();

    expect(fn () => $category->forceDelete())->toThrow(DomainException::class);
});

it('allows soft-deleting a page whose only children are trashed', function (): void {
    $category = Page::factory()->category()->create();
    $article = Page::factory()->article()->create(['parent_id' => $category->id]);
    $article->delete();

    $category->delete();

    expect($category->trashed())->toBeTrue();
});
