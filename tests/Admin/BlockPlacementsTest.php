<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Fields\BlockContent;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\ArticleOnlyBlock;
use Tbtop\Cms\Tests\Fixtures\LayoutOnlyBlock;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class, LayoutOnlyBlock::class, ArticleOnlyBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function pageOfType(string $type, ?int $parentId = null): Page
{
    return Page::create([
        'type' => $type,
        'status' => PageStatus::Published,
        'name' => ['en' => 'Test'],
        'parent_id' => $parentId,
    ]);
}

/** @return list<string> */
function addBlockActionNames(array $tabs): array
{
    $addTab = collect($tabs)->firstWhere('label', __('tbtop-cms::pages.tabs_edit.add_block'));
    expect($addTab)->not->toBeNull();

    $actions = [];
    collectNodesByKind($addTab['body'], 'action', $actions);

    return array_column($actions, 'name');
}

/** @return list<array<string, mixed>> */
function pageFormTabsFor(AdminUser $admin, int $pageId): array
{
    $structure = test()->actingAs($admin, 'web')
        ->get("/admin/pages/{$pageId}/edit", ['X-Inertia' => 'true'])
        ->assertOk()
        ->json();

    $form = findNodeByKind($structure['props']['structure'], 'form');
    $tabsNode = findNodeByKind($form, 'tabs');
    expect($tabsNode)->not->toBeNull();

    return $tabsNode['options']['tabs'];
}

it('offers a layout-only block in the layout editor add-block actions', function (): void {
    $structure = test()->actingAs(PageEditHelpers::admin(), 'web')
        ->get('/admin/layout/' . Slots::HEADER . '/edit', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $addTab = findLayoutAddBlockTab($structure);
    $actions = [];
    collectNodesByKind($addTab, 'action', $actions);

    expect(array_column($actions, 'name'))->toContain('layoutBlockAdd_LayoutOnly');
});

it('does not offer a layout-only block on the page editor add-block actions', function (): void {
    $page = pageOfType('page');

    $tabs = PageEditHelpers::formTabs($page->id);
    $names = addBlockActionNames($tabs);

    expect($names)->not->toContain('pageBlockAdd_LayoutOnly');
});

it('offers a default (pages-placement) block on page, category, and article page editors', function (): void {
    $admin = PageEditHelpers::admin();
    $category = pageOfType('category');

    foreach ([pageOfType('page'), $category, pageOfType('article', $category->id)] as $page) {
        $names = addBlockActionNames(pageFormTabsFor($admin, $page->id));

        expect($names)->toContain('pageBlockAdd_Simple');
    }
});

it('does not offer a default (pages-placement) block on the layout editor', function (): void {
    $structure = test()->actingAs(PageEditHelpers::admin(), 'web')
        ->get('/admin/layout/' . Slots::HEADER . '/edit', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $addTab = findLayoutAddBlockTab($structure);
    $actions = [];
    collectNodesByKind($addTab, 'action', $actions);

    expect(array_column($actions, 'name'))->not->toContain('layoutBlockAdd_Simple');
});

it('offers a block returning [article] only on the article page editor, not on page or category', function (): void {
    $admin = PageEditHelpers::admin();
    $category = pageOfType('category');
    $article = pageOfType('article', $category->id);
    $page = pageOfType('page');

    $articleNames = addBlockActionNames(pageFormTabsFor($admin, $article->id));
    expect($articleNames)->toContain('pageBlockAdd_ArticleOnly');

    $pageNames = addBlockActionNames(pageFormTabsFor($admin, $page->id));
    expect($pageNames)->not->toContain('pageBlockAdd_ArticleOnly');

    $categoryNames = addBlockActionNames(pageFormTabsFor($admin, $category->id));
    expect($categoryNames)->not->toContain('pageBlockAdd_ArticleOnly');
});

it('lists every registered kind from BlockContent::make() with no placement, for host back-compat', function (): void {
    $s = app(S::class);
    $node = json_decode(json_encode(BlockContent::make($s)), true);

    $kindField = findFieldByName($node, 'kind');
    $values = array_column($kindField['options']['options'] ?? [], 'value');

    expect($values)->toContain('Simple', 'LayoutOnly', 'ArticleOnly');
});

it('still renders a layout-only kind on the frontend and still shows its edit tab, even though it is filtered from add-block actions', function (): void {
    config(['cache.default' => 'array']);
    app(Cms::class)->frontendRoutes();

    $page = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [
            ['id' => 'lo1', 'kind' => 'LayoutOnly', 'active' => true, 'sort' => 0, 'data' => ['label' => 'Still here']],
        ],
    ]);

    // Frontend render is unaffected by placements — resolve/render never filters.
    test()->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.lo1.type', 'LayoutOnly');

    // The block's own edit tab still shows (tabs go through has()/get(), not the filtered list).
    $tabs = PageEditHelpers::formTabs($page->id);
    $labels = array_column($tabs, 'label');
    expect($labels)->toContain('LayoutOnly');
});

/** @return array<string, mixed> */
function findLayoutAddBlockTab(array $structure): array
{
    $form = findNodeByKind($structure, 'form');
    $tabsNode = findNodeByKind($form, 'tabs');
    $addTab = collect($tabsNode['options']['tabs'])->firstWhere('label', __('tbtop-cms::pages.tabs_edit.add_block'));
    expect($addTab)->not->toBeNull();

    return $addTab['body'];
}
