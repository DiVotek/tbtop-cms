<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('rejects moving a subtree when a descendant would exceed its maximum depth', function (): void {
    $newRoot = Page::factory()->category()->create();
    $subtreeRoot = Page::factory()->category()->create();
    $child = Page::factory()->category()->create(['parent_id' => $subtreeRoot->id]);
    $grandchild = Page::factory()->category()->create(['parent_id' => $child->id]);

    expect(fn () => $subtreeRoot->update(['parent_id' => $newRoot->id]))
        ->toThrow(DomainException::class);

    expect($subtreeRoot->fresh()->parent_id)->toBeNull()
        ->and($child->fresh()->parent_id)->toBe($subtreeRoot->id)
        ->and($grandchild->fresh()->parent_id)->toBe($child->id);
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

it('rejects restoring a child whose parent type is no longer registered with a DomainException', function (): void {
    $category = Page::factory()->category()->create();
    $article = Page::factory()->article()->create(['parent_id' => $category->id]);
    $article->delete();
    Page::query()->whereKey($category->id)->toBase()->update(['type' => 'legacy']);

    expect(fn () => $article->restore())
        ->toThrow(DomainException::class, 'Page type [legacy] is not registered.');
});

it('moves a subtree that holds a page of an unregistered type', function (): void {
    $target = Page::factory()->category()->create();
    $moved = Page::factory()->category()->create();
    $legacy = Page::factory()->category()->create(['parent_id' => $moved->id]);
    Page::query()->whereKey($legacy->id)->toBase()->update(['type' => 'legacy']);

    $moved->parent_id = $target->id;
    $moved->save();

    expect($moved->fresh()->parent_id)->toBe($target->id);
});

it('reports hierarchy violations in the admin locale', function (): void {
    app()->setLocale('uk');

    expect(fn () => Page::factory()->article()->create())
        ->toThrow(DomainException::class, 'Тип сторінки [article] потребує батьківської сторінки.');

    $a = Page::factory()->category()->create();
    $b = Page::factory()->category()->create(['parent_id' => $a->id]);
    $a->parent_id = $b->id;

    expect(fn () => $a->save())
        ->toThrow(DomainException::class, 'Сторінку не можна розмістити під собою або своїми нащадками.');
});
