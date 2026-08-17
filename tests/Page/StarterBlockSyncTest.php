<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\StarterBlockSync;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('leaves a page block whose tpl_id still exists in the starter completely untouched', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'tpl-1', 'kind' => 'Hero']]],
    ]);
    $article = Page::factory()->article()->create([
        'parent_id' => $category->id,
        'content' => [
            ['id' => 'page-block-1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Filled in on the page'], 'tpl_id' => 'tpl-1'],
        ],
    ]);

    (new StarterBlockSync)->apply($category, 'article');
    $article->refresh();

    expect($article->content)->toHaveCount(1)
        ->and($article->content[0])->toBe([
            'id' => 'page-block-1', 'kind' => 'Hero', 'active' => true,
            'data' => ['title' => 'Filled in on the page'], 'tpl_id' => 'tpl-1', 'sort' => 0,
        ]);
});

it('inserts a new starter element as an inactive, empty-data block, positioned after the nearest earlier starter block present on the page', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [
            ['id' => 'tpl-1', 'kind' => 'Hero'],
            ['id' => 'tpl-2', 'kind' => 'Simple'],
            ['id' => 'tpl-3', 'kind' => 'Hero'],
        ]],
    ]);
    $article = Page::factory()->article()->create([
        'parent_id' => $category->id,
        'content' => [
            ['id' => 'p1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'First'], 'tpl_id' => 'tpl-1'],
            ['id' => 'p3', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Third'], 'tpl_id' => 'tpl-3'],
        ],
    ]);

    (new StarterBlockSync)->apply($category, 'article');
    $article->refresh();

    expect(array_column($article->content, 'tpl_id'))->toBe(['tpl-1', 'tpl-2', 'tpl-3'])
        ->and($article->content[1]['kind'])->toBe('Simple')
        ->and($article->content[1]['active'])->toBeFalse()
        ->and($article->content[1]['data'])->toBe([])
        ->and($article->content[1]['id'])->not->toBe('p1')
        ->and($article->content[1]['id'])->not->toBe('p3')
        // The two untouched blocks keep their content exactly as it was.
        ->and($article->content[0]['data'])->toBe(['title' => 'First'])
        ->and($article->content[2]['data'])->toBe(['title' => 'Third']);
});

it('assigns sort by the final position after insertion', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [
            ['id' => 'tpl-1', 'kind' => 'Hero'],
            ['id' => 'tpl-2', 'kind' => 'Simple'],
        ]],
    ]);
    $article = Page::factory()->article()->create([
        'parent_id' => $category->id,
        'content' => [['id' => 'p1', 'kind' => 'Hero', 'tpl_id' => 'tpl-1', 'active' => true, 'data' => []]],
    ]);

    (new StarterBlockSync)->apply($category, 'article');
    $article->refresh();

    expect(array_column($article->content, 'sort'))->toBe([0, 1]);
});

it('inserts a new starter block at the start when no earlier starter block is present on the page', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [
            ['id' => 'tpl-1', 'kind' => 'Hero'],
            ['id' => 'tpl-2', 'kind' => 'Simple'],
        ]],
    ]);
    $article = Page::factory()->article()->create([
        'parent_id' => $category->id,
        'content' => [['id' => 'p2', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Existing'], 'tpl_id' => 'tpl-2']],
    ]);

    (new StarterBlockSync)->apply($category, 'article');
    $article->refresh();

    expect(array_column($article->content, 'tpl_id'))->toBe(['tpl-1', 'tpl-2'])
        ->and($article->content[1]['data'])->toBe(['heading' => 'Existing']);
});

it('removes a page block (and its content) whose tpl_id no longer exists in the starter', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'tpl-1', 'kind' => 'Hero']]],
    ]);
    $article = Page::factory()->article()->create([
        'parent_id' => $category->id,
        'content' => [
            ['id' => 'p1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Kept'], 'tpl_id' => 'tpl-1'],
            ['id' => 'p2', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Removed from starter'], 'tpl_id' => 'tpl-gone'],
        ],
    ]);

    (new StarterBlockSync)->apply($category, 'article');
    $article->refresh();

    expect($article->content)->toHaveCount(1)
        ->and($article->content[0]['id'])->toBe('p1');
});

it('leaves an editor-added block (no tpl_id) untouched, in its original position', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'tpl-1', 'kind' => 'Hero']]],
    ]);
    $article = Page::factory()->article()->create([
        'parent_id' => $category->id,
        'content' => [
            ['id' => 'p1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'From starter'], 'tpl_id' => 'tpl-1'],
            ['id' => 'editor-block', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Added by editor']],
        ],
    ]);

    (new StarterBlockSync)->apply($category, 'article');
    $article->refresh();

    expect($article->content)->toHaveCount(2)
        ->and($article->content[1]['id'])->toBe('editor-block')
        ->and($article->content[1])->not->toHaveKey('tpl_id')
        ->and($article->content[1]['data'])->toBe(['title' => 'Added by editor']);
});

it('does not touch a subtree whose subcategory defines its own non-empty starter of the same type', function (): void {
    $root = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'root-tpl', 'kind' => 'Hero']]],
    ]);
    $managedSub = Page::factory()->category()->create([
        'parent_id' => $root->id,
        'starter_blocks' => ['article' => [['id' => 'sub-tpl', 'kind' => 'Simple']]],
    ]);
    $articleUnderSub = Page::factory()->article()->create([
        'parent_id' => $managedSub->id,
        'content' => [['id' => 'p1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Untouched'], 'tpl_id' => 'root-tpl']],
    ]);

    $count = (new StarterBlockSync)->apply($root, 'article');
    $articleUnderSub->refresh();

    expect($count)->toBe(0)
        ->and($articleUnderSub->content[0]['data'])->toBe(['title' => 'Untouched']);
});

it('still syncs the subcategory itself when applying a category starter, while sparing its subtree', function (): void {
    // A subcategory's OWN category starter speaks for its children, not for
    // the subcategory-as-a-page: at creation the subcategory inherited from
    // THIS ancestor's starter (its own did not exist yet), so the ancestor's
    // force-apply keeps governing it — but never anything below it.
    $root = Page::factory()->category()->create([
        'starter_blocks' => ['category' => [['id' => 'root-cat-tpl', 'kind' => 'Hero']]],
    ]);
    $managedSub = Page::factory()->category()->create([
        'parent_id' => $root->id,
        'starter_blocks' => ['category' => [['id' => 'sub-cat-tpl', 'kind' => 'Simple']]],
        'content' => [['id' => 'stale', 'kind' => 'Simple', 'active' => true, 'data' => [], 'tpl_id' => 'gone-tpl']],
    ]);
    $subSub = Page::factory()->category()->create([
        'parent_id' => $managedSub->id,
        'content' => [['id' => 'deep', 'kind' => 'Simple', 'active' => true, 'data' => ['title' => 'Untouched'], 'tpl_id' => 'sub-cat-tpl']],
    ]);

    $count = (new StarterBlockSync)->apply($root, 'category');
    $managedSub->refresh();
    $subSub->refresh();

    expect($count)->toBe(1)
        ->and(array_column($managedSub->content, 'tpl_id'))->toBe(['root-cat-tpl'])
        ->and($subSub->content[0]['data'])->toBe(['title' => 'Untouched']);
});

it('does not touch a fallback-copied page (no tpl_id anywhere) even though it sits in scope', function (): void {
    $root = Page::factory()->category()->create([
        'starter_blocks' => ['category' => [['id' => 'root-tpl', 'kind' => 'Hero']]],
    ]);
    // A subcategory that inherited via the parent-structure fallback (no
    // ancestor 'category' starter existed at the time it was created) — its
    // blocks carry no tpl_id, so a later starter added on $root must not
    // start rewriting them as if they came from it.
    $fallbackSub = Page::factory()->category()->create([
        'parent_id' => $root->id,
        'content' => [['id' => 'p1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Fallback copy, no provenance']]],
    ]);

    (new StarterBlockSync)->apply($root, 'category');
    $fallbackSub->refresh();

    // The starter block is inserted (nothing on the page claims its
    // tpl_id, so it lands at the very start — see insertNewStarterBlocks());
    // the untagged fallback block is left exactly as it was, just pushed
    // one position later.
    $fallbackBlock = collect($fallbackSub->content)->firstWhere('id', 'p1');
    expect($fallbackSub->content)->toHaveCount(2)
        ->and($fallbackBlock)->not->toBeNull()
        ->and($fallbackBlock)->not->toHaveKey('tpl_id')
        ->and($fallbackBlock['data'])->toBe(['title' => 'Fallback copy, no provenance']);
});

it('recurses through an unmanaged subcategory to reach articles further down', function (): void {
    $root = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'root-tpl', 'kind' => 'Hero']]],
    ]);
    $plainSub = Page::factory()->category()->create(['parent_id' => $root->id]);
    $article = Page::factory()->article()->create(['parent_id' => $plainSub->id, 'content' => []]);

    $count = (new StarterBlockSync)->apply($root, 'article');
    $article->refresh();

    expect($count)->toBe(1)
        ->and($article->content)->toHaveCount(1)
        ->and($article->content[0]['tpl_id'])->toBe('root-tpl')
        ->and($article->content[0]['active'])->toBeFalse();
});

it('reports the affected count without side effects', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 't1', 'kind' => 'Hero']]],
    ]);
    Page::factory()->article()->count(3)->create(['parent_id' => $category->id]);
    Page::factory()->category()->create(['parent_id' => $category->id]); // wrong type, excluded

    expect((new StarterBlockSync)->affectedCount($category, 'article'))->toBe(3);
});

it('saves each touched page and raises PageSaved(wasCreated: false) so publish caches invalidate', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 't1', 'kind' => 'Hero']]],
    ]);
    $article = Page::factory()->article()->create(['parent_id' => $category->id, 'content' => []]);
    Event::fake([PageSaved::class]);

    (new StarterBlockSync)->apply($category, 'article');

    Event::assertDispatched(PageSaved::class, fn (PageSaved $e): bool => $e->page->is($article) && $e->wasCreated === false);
});
