<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\StarterBlocks;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('turns the nearest ancestor category\'s article starter into fresh, active, empty blocks with tpl_id provenance', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [
            ['id' => 'starter-1', 'kind' => 'Hero'],
        ]],
    ]);
    $article = Page::factory()->article()->make(['parent_id' => $category->id]);

    $content = (new StarterBlocks)->contentFor($article);

    expect($content)->toHaveCount(1)
        ->and($content[0]['kind'])->toBe('Hero')
        ->and($content[0]['active'])->toBeTrue()
        ->and($content[0]['data'])->toBe([])
        ->and($content[0]['sort'])->toBe(0)
        ->and($content[0]['id'])->not->toBe('starter-1')
        ->and($content[0]['tpl_id'])->toBe('starter-1');
});

it('preserves starter order in the sort field of the resulting blocks', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [
            ['id' => 's1', 'kind' => 'Hero'],
            ['id' => 's2', 'kind' => 'Simple'],
        ]],
    ]);
    $article = Page::factory()->article()->make(['parent_id' => $category->id]);

    $content = (new StarterBlocks)->contentFor($article);

    expect(array_column($content, 'kind'))->toBe(['Hero', 'Simple'])
        ->and(array_column($content, 'sort'))->toBe([0, 1])
        ->and(array_column($content, 'tpl_id'))->toBe(['s1', 's2']);
});

it('lets a closer subcategory starter override a further ancestor\'s starter of the same type', function (): void {
    $root = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'root-1', 'kind' => 'Hero']]],
    ]);
    $sub = Page::factory()->category()->create([
        'parent_id' => $root->id,
        'starter_blocks' => ['article' => [['id' => 'sub-1', 'kind' => 'Simple']]],
    ]);
    $article = Page::factory()->article()->make(['parent_id' => $sub->id]);

    $content = (new StarterBlocks)->contentFor($article);

    expect($content)->toHaveCount(1)
        ->and($content[0]['tpl_id'])->toBe('sub-1')
        ->and($content[0]['kind'])->toBe('Simple');
});

it('walks past a category with no starter of its own to find one further up', function (): void {
    $root = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'root-1', 'kind' => 'Hero']]],
    ]);
    $middle = Page::factory()->category()->create(['parent_id' => $root->id]);
    $article = Page::factory()->article()->make(['parent_id' => $middle->id]);

    $content = (new StarterBlocks)->contentFor($article);

    expect($content)->toHaveCount(1)
        ->and($content[0]['tpl_id'])->toBe('root-1');
});

it('falls back to copying the immediate parent category\'s own block structure, without tpl_id or the parent\'s text, for a subcategory with no ancestor starter', function (): void {
    $parent = Page::factory()->category()->create([
        'content' => [['id' => 'p1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Parent text']]],
    ]);
    $newCategory = Page::factory()->category()->make(['parent_id' => $parent->id]);

    $content = (new StarterBlocks)->contentFor($newCategory);

    expect($content)->toHaveCount(1)
        ->and($content[0]['kind'])->toBe('Hero')
        ->and($content[0]['active'])->toBeTrue()
        ->and($content[0]['data'])->toBe([])
        ->and($content[0]['id'])->not->toBe('p1')
        ->and($content[0])->not->toHaveKey('tpl_id');
});

it('never falls back to the parent content for a new article (fallback is category-only)', function (): void {
    $parent = Page::factory()->category()->create([
        'content' => [['id' => 'p1', 'kind' => 'Hero', 'data' => ['title' => 'Parent content']]],
    ]);
    $article = Page::factory()->article()->make(['parent_id' => $parent->id]);

    $content = (new StarterBlocks)->contentFor($article);

    expect($content)->toBe([]);
});

it('does not inherit anything for type=page', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 's1', 'kind' => 'Hero']]],
    ]);
    // 'page' cannot normally sit under a category, but contentFor() is
    // exercised directly here to prove the type gate itself, independent of
    // PageHierarchy's own placement rules.
    $page = Page::factory()->make(['type' => 'page', 'parent_id' => $category->id]);

    expect((new StarterBlocks)->contentFor($page))->toBe([]);
});

it('returns nothing for a page with no parent', function (): void {
    $article = Page::factory()->article()->make(['parent_id' => null]);

    expect((new StarterBlocks)->contentFor($article))->toBe([]);
});

it('seeds a new article\'s content via PageForm::save on create', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'starter-1', 'kind' => 'Hero']]],
    ]);

    $article = PageForm::save([
        'type' => 'article',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'New article'],
        'parent_id' => (string) $category->id,
    ]);

    expect($article->content)->toHaveCount(1)
        ->and($article->content[0]['kind'])->toBe('Hero')
        ->and($article->content[0]['active'])->toBeTrue()
        ->and($article->content[0]['data'])->toBe([])
        ->and($article->content[0]['tpl_id'])->toBe('starter-1');
});
