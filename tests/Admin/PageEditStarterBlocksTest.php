<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class, HeroBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

it('shows the Starter blocks section for a category page', function (): void {
    $category = Page::factory()->category()->create(['name' => ['en' => 'Blog']]);

    $tabs = editFormTabs($category->id);
    $sections = [];
    collectNodesByKind($tabs[0]['body'], 'section', $sections);

    $starterSection = collect($sections)->first(
        fn (array $s): bool => ($s['options']['title'] ?? null) === 'Starter blocks',
    );

    expect($starterSection)->not->toBeNull()
        ->and($starterSection['options']['collapsible'] ?? null)->toBeTrue()
        ->and($starterSection['options']['collapsed'] ?? null)->toBeTrue();
});

it('omits the Starter blocks section for an article page', function (): void {
    $category = Page::factory()->category()->create();
    $article = Page::factory()->article()->create(['parent_id' => $category->id]);

    $tabs = editFormTabs($article->id);
    $sections = [];
    collectNodesByKind($tabs[0]['body'], 'section', $sections);

    $starterSection = collect($sections)->first(
        fn (array $s): bool => ($s['options']['title'] ?? null) === 'Starter blocks',
    );

    expect($starterSection)->toBeNull();
});

it('omits the Starter blocks section for a plain page', function (): void {
    $page = Page::factory()->create(['type' => 'page']);

    $tabs = editFormTabs($page->id);
    $sections = [];
    collectNodesByKind($tabs[0]['body'], 'section', $sections);

    $starterSection = collect($sections)->first(
        fn (array $s): bool => ($s['options']['title'] ?? null) === 'Starter blocks',
    );

    expect($starterSection)->toBeNull();
});

it('shows a muted empty-state message and no block rows when a starter has no blocks yet', function (): void {
    $category = Page::factory()->category()->create();

    $tabs = editFormTabs($category->id);
    $texts = [];
    collectNodesByKind($tabs[0]['body'], 'displayText', $texts);

    $emptyText = collect($texts)->first(fn (array $t): bool => ($t['options']['content'] ?? null) === 'No blocks yet.');

    expect($emptyText)->not->toBeNull()
        ->and($emptyText['options']['variant'] ?? null)->toBe('muted');
});

it('frames the block list in a bordered card section', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 't1', 'kind' => 'Simple']]],
    ]);

    $tabs = editFormTabs($category->id);
    $sections = [];
    collectNodesByKind($tabs[0]['body'], 'section', $sections);

    $cardSections = array_filter($sections, fn (array $s): bool => ($s['options']['variant'] ?? null) === 'card');
    expect($cardSections)->not->toBeEmpty();
});

it('lists one "+ kind" add action per registered block kind', function (): void {
    $category = Page::factory()->category()->create();

    $tabs = editFormTabs($category->id);
    $actions = [];
    collectNodesByKind($tabs[0]['body'], 'action', $actions);
    $names = array_column($actions, 'name');

    expect($names)->toContain('pageStarterBlockAdd_article_Simple', 'pageStarterBlockAdd_article_Hero')
        ->toContain('pageStarterBlockAdd_category_Simple', 'pageStarterBlockAdd_category_Hero');
});

it('lists a row per starter block with move and remove actions, uniquely named per starter type', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => [
            'article' => [['id' => 'a1', 'kind' => 'Simple'], ['id' => 'a2', 'kind' => 'Hero']],
            'category' => [['id' => 'c1', 'kind' => 'Simple']],
        ],
    ]);

    $tabs = editFormTabs($category->id);
    $actions = [];
    collectNodesByKind($tabs[0]['body'], 'action', $actions);
    $names = array_column($actions, 'name');

    // article tab: a1 is first (no move-up), a2 is last (no move-down)
    expect($names)->toContain('pageStarterBlockMoveDown_article_a1', 'pageStarterBlockRemove_article_a1')
        ->not->toContain('pageStarterBlockMoveUp_article_a1')
        ->toContain('pageStarterBlockMoveUp_article_a2', 'pageStarterBlockRemove_article_a2')
        ->not->toContain('pageStarterBlockMoveDown_article_a2')
        // category tab uses its own suffix, no collision with the article tab's c1-shaped names
        ->toContain('pageStarterBlockRemove_category_c1')
        ->not->toContain('pageStarterBlockMoveUp_category_c1', 'pageStarterBlockMoveDown_category_c1');
});

/**
 * Every starter-block action declares needs: [] and never reads $ctx->form
 * (see PageEditStarterBlocks's class docblock) — payload.form is meaningless
 * to them now, so the real client sends none. ['payload' => []] matches how
 * every other needs: [] action on this page is tested (pageEditDelete,
 * pagePreviewLinkCopy in PageEditPageTest).
 */
it('adds a block to the article starter via pageStarterBlockAdd_article_{kind} and redirects back', function (): void {
    $category = Page::factory()->category()->create();

    $response = test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockAdd_article_Simple",
        ['payload' => []],
    );

    $response->assertOk();
    $category->refresh();

    expect($category->starter_blocks['article'])->toHaveCount(1)
        ->and($category->starter_blocks['article'][0]['kind'])->toBe('Simple')
        ->and(array_keys($category->starter_blocks['article'][0]))->toBe(['id', 'kind']);

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect', 'notify');
});

it('appends a second added block after the first, in the same starter', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'a1', 'kind' => 'Simple']]],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockAdd_article_Hero",
        ['payload' => []],
    )->assertOk();

    $category->refresh();

    expect(array_column($category->starter_blocks['article'], 'kind'))->toBe(['Simple', 'Hero']);
});

it('removes a starter block via pageStarterBlockRemove_{type}_{id}', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'a1', 'kind' => 'Simple'], ['id' => 'a2', 'kind' => 'Hero']]],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockRemove_article_a1",
        ['payload' => []],
    )->assertOk();

    $category->refresh();

    expect(array_column($category->starter_blocks['article'], 'id'))->toBe(['a2']);
});

it('moves a starter block up, swapping it with its predecessor', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [
            ['id' => 'a1', 'kind' => 'Simple'],
            ['id' => 'a2', 'kind' => 'Hero'],
        ]],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockMoveUp_article_a2",
        ['payload' => []],
    )->assertOk();

    $category->refresh();

    expect(array_column($category->starter_blocks['article'], 'id'))->toBe(['a2', 'a1']);
});

it('moves a starter block down, swapping it with its successor', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [
            ['id' => 'a1', 'kind' => 'Simple'],
            ['id' => 'a2', 'kind' => 'Hero'],
        ]],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockMoveDown_article_a1",
        ['payload' => []],
    )->assertOk();

    $category->refresh();

    expect(array_column($category->starter_blocks['article'], 'id'))->toBe(['a2', 'a1']);
});

it('keeps the category starter untouched when a block is added to the article starter (no cross-type leakage)', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['category' => [['id' => 'c1', 'kind' => 'Simple']]],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockAdd_article_Hero",
        ['payload' => []],
    )->assertOk();

    $category->refresh();

    expect(array_column($category->starter_blocks['category'], 'id'))->toBe(['c1'])
        ->and(array_column($category->starter_blocks['article'], 'kind'))->toBe(['Hero']);
});

it('leaves the page\'s own content and every other column untouched — only starter_blocks changes', function (): void {
    $category = Page::factory()->category()->create([
        'name' => ['en' => 'Original name'],
        'content' => [['id' => 'existing-block', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Keep me']]],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockAdd_article_Hero",
        ['payload' => []],
    )->assertOk();

    $category->refresh();

    expect($category->content)->toBe([
        ['id' => 'existing-block', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Keep me']],
    ])->and($category->getTranslation('name', 'en'))->toBe('Original name');
});

/**
 * The bug this whole rewrite exists to fix: an add/move/remove action used
 * to declare needs: ['form'], which made ActionController run
 * $request->validate() against the WHOLE pageEditor form's rules — including
 * every other block's fields — before the handler ran. A page carrying an
 * invalid block elsewhere (e.g. a Hero with its required title left empty)
 * failed that validation on every starter-block click, and the client
 * silently dropped the response: every starter button on the page went dead
 * with no visible error, independent of which starter or block was clicked.
 */
it('adds a starter block even when the page\'s own content has an invalid required field', function (): void {
    $category = Page::factory()->category()->create([
        'content' => [
            ['id' => 'invalid-hero', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => '']]],
        ],
    ]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockAdd_article_Simple",
        ['payload' => []],
    );

    $response->assertOk();
    expect($category->refresh()->starter_blocks['article'])->toHaveCount(1);
});

it('removes a starter block even when the page\'s own content has an invalid required field', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'a1', 'kind' => 'Simple']]],
        'content' => [
            ['id' => 'invalid-hero', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => '']]],
        ],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockRemove_article_a1",
        ['payload' => []],
    )->assertOk();

    expect($category->refresh()->starter_blocks['article'])->toBe([]);
});

it('moves a starter block even when the page\'s own content has an invalid required field', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'a1', 'kind' => 'Simple'], ['id' => 'a2', 'kind' => 'Hero']]],
        'content' => [
            ['id' => 'invalid-hero', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => '']]],
        ],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockMoveUp_article_a2",
        ['payload' => []],
    )->assertOk();

    expect(array_column($category->refresh()->starter_blocks['article'], 'id'))->toBe(['a2', 'a1']);
});

/**
 * PageObserver::saving() bumps lock_version on any real Eloquent save() with
 * a dirty attribute. A starter-block action must not trigger that: the
 * user's already-open edit form still carries the lock_version it loaded
 * with, and a bump here would make its next legitimate "Save" bounce off
 * PageForm::guardLockVersion() as a false PageConflictException even though
 * nothing the form itself was editing actually changed underneath it.
 */
it('does not bump lock_version when a starter-block action saves', function (): void {
    $category = Page::factory()->category()->create();
    $lockVersionBefore = $category->refresh()->lock_version;

    test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockAdd_article_Simple",
        ['payload' => []],
    )->assertOk();

    expect($category->refresh()->lock_version)->toBe($lockVersionBefore);
});

it('does not make a concurrent form save with the original lock_version bounce off a false conflict', function (): void {
    $category = Page::factory()->category()->create();
    $originalLockVersion = $category->lock_version;
    $session = test()->actingAs(makePageEditAdmin(), 'web');

    $session->postJson(
        "/admin/pages/{$category->id}/edit/actions/pageStarterBlockAdd_article_Simple",
        ['payload' => []],
    )->assertOk();

    // Simulates a browser tab that had the edit form open (and its
    // lock_version captured) BEFORE the starter-block action ran elsewhere.
    $session->post(
        "/admin/pages/{$category->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'lock_version' => $originalLockVersion, 'name' => ['en' => 'Saved after starter add']],
    )->assertRedirect()->assertSessionHasNoErrors();

    expect($category->refresh()->getTranslation('name', 'en'))->toBe('Saved after starter add');
});

it('hides the Apply action for an empty starter and shows it once the starter has blocks', function (): void {
    $empty = Page::factory()->category()->create();
    $withStarter = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 't1', 'kind' => 'Simple']]],
    ]);

    // One shared admin session for both fetches — editFormTabs()/
    // pageEditStructure() creates a fresh admin (fixed email) on every call,
    // so calling it twice in one test would collide on the email unique
    // constraint.
    $session = test()->actingAs(makePageEditAdmin(), 'web');
    $tabsFor = static function (int $pageId) use ($session): array {
        $form = findNodeByKind(
            $session->get("/admin/pages/{$pageId}/edit", ['X-Inertia' => 'true'])->assertOk()->json('props.structure'),
            'form',
        );

        return findNodeByKind($form, 'tabs')['options']['tabs'];
    };

    $emptyActions = [];
    collectNodesByKind($tabsFor($empty->id)[0]['body'], 'action', $emptyActions);
    expect(array_column($emptyActions, 'name'))->not->toContain('pageStarterBlocksApply_article');

    $filledActions = [];
    collectNodesByKind($tabsFor($withStarter->id)[0]['body'], 'action', $filledActions);
    expect(array_column($filledActions, 'name'))->toContain('pageStarterBlocksApply_article');
});
