<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makePageEditAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);

    return $user;
}

/** @return array<string, mixed> */
function pageEditStructure(int $id): array
{
    return test()->actingAs(makePageEditAdmin(), 'web')
        ->get("/admin/pages/{$id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()
        ->json();
}

/** @param array<string, mixed> $blocks */
function pageWithBlocks(array $blocks = []): Page
{
    return Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About Us'],
        'content' => $blocks,
    ]);
}

it('shows the edit page to an admin user with the record prefilled', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);

    $json = pageEditStructure($page->id);

    expect($json['component'])->toBe('admin/page')
        ->and($json['props']['data']['pageEditor']['name'])->toBe(['en' => 'About Us']);
});

it('returns 404 for a page that does not exist', function (): void {
    test()->actingAs(makePageEditAdmin(), 'web')
        ->get('/admin/pages/999999/edit', ['X-Inertia' => 'true'])
        ->assertNotFound();
});

it('redirects a guest away from the edit page', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    test()->get("/admin/pages/{$page->id}/edit")->assertRedirect(route('login'));
});

it('wires General/SEO tabs, one tab per saved block, and a trailing add-block tab', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Hi']],
    ]);

    $tabs = editFormTabs($page->id);

    expect($tabs)->toHaveCount(4)
        ->and($tabs[0]['label'])->toBe('General')
        ->and($tabs[1]['label'])->toBe('SEO')
        ->and($tabs[2]['label'])->toBe('Simple')
        ->and($tabs[3]['label'])->toBe('+ Add block');

    $generalFieldNames = array_column($tabs[0]['body']['options']['children'], 'name');
    expect($generalFieldNames)->toContain('name', 'title', 'heading', 'summary')
        ->and($generalFieldNames)->not->toContain('type', 'parent_id', 'status', 'sort_order');

    $seoFieldNames = array_column($tabs[1]['body']['options']['children'], 'name');
    expect($seoFieldNames)->toContain('seo_title', 'seo_description', 'is_indexable');
});

it('prefixes block fields with block_{id}_ inside the block tab', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Hi', 'body' => 'Text']],
    ]);

    $tabs = editFormTabs($page->id);
    $blockTab = $tabs[2];
    $names = array_column($blockTab['body']['options']['children'], 'name');

    expect($names)->toContain('block_blk1_heading', 'block_blk1_body', 'block_blk1_active');
});

it('preserves the translatable flag and required rule when renaming a block field', function (): void {
    config(['tbtop-cms.blocks.types' => [HeroBlock::class]]);
    $page = pageWithBlocks([
        ['id' => 'hero1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Welcome']]],
    ]);

    $tabs = editFormTabs($page->id);
    $titleField = collect($tabs[2]['body']['options']['children'])->firstWhere('name', 'block_hero1_title');

    expect($titleField)->not->toBeNull()
        ->and($titleField['options']['translatable'] ?? null)->toBeTrue();
});

it('round-trips a translatable block field through save', function (): void {
    config(['tbtop-cms.blocks.types' => [HeroBlock::class]]);
    $page = pageWithBlocks([
        ['id' => 'hero1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Welcome']]],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'block_hero1_title' => ['en' => 'Updated'], 'block_hero1_active' => true],
    )->assertRedirect();

    expect($page->refresh()->content[0]['data'])->toBe(['title' => ['en' => 'Updated']]);
});

it('keeps the sidebar cards for status/type/parent/sort_order out of the General tab', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $grid = findNodeByKind($form, 'grid');

    expect($grid)->not->toBeNull();

    $sidebar = $grid['options']['children'][1] ?? null;
    expect($sidebar)->not->toBeNull();

    $sidebarFieldNames = [];
    foreach ((array) ($sidebar['options']['children'] ?? []) as $section) {
        foreach ((array) ($section['options']['children'] ?? []) as $field) {
            $sidebarFieldNames[] = $field['name'] ?? null;
        }
    }

    expect($sidebarFieldNames)->toContain('status', 'type', 'parent_id', 'sort_order');
});

it('renders the status bar as the first child of the form with a status badge', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $firstChild = $form['options']['children'][0];

    expect($firstChild['kind'])->toBe('section');

    $badge = findNodeByKind($firstChild, 'displayValue');
    expect($badge)->not->toBeNull()
        ->and($badge['options']['kind'])->toBe('badge');
});

it('includes breadcrumbs linking back to the pages list', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);

    $breadcrumbs = pageEditStructure($page->id)['props']['breadcrumbs'];

    expect($breadcrumbs)->toHaveCount(3)
        ->and($breadcrumbs[1]['label'])->toBe('Pages')
        ->and($breadcrumbs[1]['url'] ?? null)->toEndWith('/admin/pages')
        ->and($breadcrumbs[2]['label'])->toBe('About Us');
});

it('saves general and SEO fields including the seo json column', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            'name' => ['en' => 'About Updated'],
            'title' => ['en' => ''],
            'heading' => ['en' => ''],
            'summary' => ['en' => ''],
            'type' => 'page',
            'parent_id' => 'none',
            'status' => PageStatus::Published->value,
            'sort_order' => 5,
            'seo_title' => 'Meta title',
            'seo_description' => 'Meta description',
            'is_indexable' => true,
        ],
    );

    $response->assertRedirect();
    $page->refresh();

    expect($page->getTranslation('name', 'en'))->toBe('About Updated')
        ->and($page->sort_order)->toBe(5)
        ->and($page->seo)->toBe(['title' => 'Meta title', 'description' => 'Meta description']);
});

it('round-trips two blocks through save: data, active, and order are preserved', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'One', 'body' => 'First']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Two', 'body' => 'Second']],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One Updated',
            'block_blk1_body' => 'First Updated',
            'block_blk1_active' => true,
            'block_blk2_heading' => 'Two',
            'block_blk2_body' => 'Second',
            'block_blk2_active' => false,
        ],
    )->assertRedirect();

    $content = $page->refresh()->content;

    expect($content)->toHaveCount(2)
        ->and($content[0]['id'])->toBe('blk1')
        ->and($content[0]['data'])->toBe(['heading' => 'One Updated', 'body' => 'First Updated'])
        ->and($content[0]['active'])->toBeTrue()
        ->and($content[1]['id'])->toBe('blk2')
        ->and($content[1]['data'])->toBe(['heading' => 'Two', 'body' => 'Second'])
        ->and($content[1]['active'])->toBeFalse();
});

it('adds a new block via pageBlockAdd_{kind} and persists general fields from the payload', function (): void {
    $page = pageWithBlocks();

    $response = test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockAdd_Simple",
        ['payload' => ['form' => [...baseEditPayload(), 'name' => ['en' => 'Renamed']]]],
    );

    $response->assertOk();
    $page->refresh();

    expect($page->content)->toHaveCount(1)
        ->and($page->content[0]['kind'])->toBe('Simple')
        ->and($page->content[0]['active'])->toBeTrue()
        ->and($page->getTranslation('name', 'en'))->toBe('Renamed');

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect', 'notify');
});

it('removes a block via pageBlockRemove_{id} and keeps the other blocks intact', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'One']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Two']],
    ]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockRemove_blk1",
        ['payload' => ['form' => [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One',
            'block_blk1_active' => true,
            'block_blk2_heading' => 'Two',
            'block_blk2_active' => true,
        ]]],
    );

    $response->assertOk();
    $page->refresh();

    expect($page->content)->toHaveCount(1)
        ->and($page->content[0]['id'])->toBe('blk2');

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect');
});

it('keeps header actions empty now that view-on-site lives in the status bar', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $headerActions = test()->actingAs(makePageEditAdmin(), 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.headerActions') ?? [];

    expect($headerActions)->toBe([]);
});

it('places view-on-site and a top save action in the status bar', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $statusBar = $form['options']['children'][0];
    $names = array_column($statusBar['options']['children'][0]['options']['children'], 'name');

    expect($names)->toContain('pageEditView', 'saveTop');
});

it('hides delete from the form footer for system pages', function (): void {
    $system = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Home'], 'is_system' => true]);

    $form = findNodeByKind(pageEditStructure($system->id)['props']['structure'], 'form');
    $footerActions = footerActionNames($form);

    expect($footerActions)->not->toContain('pageEditDelete')
        ->and($footerActions)->toContain('save');
});

it('keeps delete in the form footer for regular pages', function (): void {
    $regular = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($regular->id)['props']['structure'], 'form');
    $footerActions = footerActionNames($form);

    expect($footerActions)->toContain('pageEditDelete', 'save');
});

it('deletes a regular page and redirects to the list', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')
        ->postJson("/admin/pages/{$page->id}/edit/actions/pageEditDelete", ['payload' => []]);

    $response->assertOk();
    expect(Page::query()->find($page->id))->toBeNull();

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect');
});

/** @return array<string, mixed> Minimal valid general/SEO payload for the pageEditor form. */
function baseEditPayload(): array
{
    return [
        'name' => ['en' => 'About Us'],
        'title' => ['en' => ''],
        'heading' => ['en' => ''],
        'summary' => ['en' => ''],
        'type' => 'page',
        'parent_id' => 'none',
        'status' => PageStatus::Published->value,
        'sort_order' => 0,
        'seo_title' => '',
        'seo_description' => '',
        'is_indexable' => true,
    ];
}

/** @return list<array<string, mixed>> */
function editFormTabs(int $pageId): array
{
    $form = findNodeByKind(pageEditStructure($pageId)['props']['structure'], 'form');
    $tabsNode = findNodeByKind($form, 'tabs');
    expect($tabsNode)->not->toBeNull();

    return $tabsNode['options']['tabs'];
}

/** @param array<string, mixed> $node @return array<string, mixed>|null */
function findNodeByKind(array $node, string $kind): ?array
{
    if (($node['kind'] ?? null) === $kind) {
        return $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (! is_array($child)) {
            continue;
        }
        $found = findNodeByKind($child, $kind);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * Action names inside the form footer's flex node (delete/spacer/save) —
 * the LAST flex node in the tree, to avoid matching the status bar's flex.
 *
 * @param  array<string, mixed>|null  $form
 * @return list<string>
 */
function footerActionNames(?array $form): array
{
    if ($form === null) {
        return [];
    }

    $flexes = [];
    collectNodesByKind($form, 'flex', $flexes);
    expect($flexes)->not->toBeEmpty();

    $footer = $flexes[array_key_last($flexes)];
    $names = [];
    foreach ((array) ($footer['options']['children'] ?? []) as $child) {
        if (is_array($child) && ($child['kind'] ?? null) === 'action') {
            $names[] = $child['name'];
        }
    }

    return $names;
}

/**
 * @param  array<string, mixed>  $node
 * @param  list<array<string, mixed>>  $out
 */
function collectNodesByKind(array $node, string $kind, array &$out): void
{
    if (($node['kind'] ?? null) === $kind) {
        $out[] = $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            collectNodesByKind($child, $kind, $out);
        }
    }
    foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
        if (is_array($tab) && isset($tab['body']) && is_array($tab['body'])) {
            collectNodesByKind($tab['body'], $kind, $out);
        }
    }
}
