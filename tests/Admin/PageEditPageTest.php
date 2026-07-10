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
    expect($generalFieldNames)->toContain('name', 'title', 'heading', 'summary', 'body')
        ->and($generalFieldNames)->not->toContain('type', 'parent_id', 'status', 'sort_order');

    $seoFieldNames = [];
    collectFieldNames($tabs[1]['body'], $seoFieldNames);
    expect($seoFieldNames)->toContain('seo_title', 'seo_description', 'seo_focus_keyword', 'og_title', 'og_description', 'og_image')
        ->and($seoFieldNames)->not->toContain('is_indexable');
});

it('prefixes block fields with block_{id}_ inside the block tab', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Hi', 'body' => 'Text']],
    ]);

    $tabs = editFormTabs($page->id);
    $blockTab = $tabs[2];
    $names = [];
    collectFieldNames($blockTab['body'], $names);

    expect($names)->toContain('block_blk1_heading', 'block_blk1_body', 'block_blk1_active', 'block_blk1_sort');
});

it('orders block tabs by sort, not by their position in content', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 2, 'data' => ['heading' => 'First saved']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'Second saved']],
    ]);

    $tabs = editFormTabs($page->id);

    // Both blocks share the label "Simple", so identify each tab by the
    // block_{id}_sort field it carries rather than by label text.
    $firstNames = [];
    collectFieldNames($tabs[2]['body'], $firstNames);
    $secondNames = [];
    collectFieldNames($tabs[3]['body'], $secondNames);

    expect($firstNames)->toContain('block_blk2_sort')
        ->and($secondNames)->toContain('block_blk1_sort');
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

it('preserves a block whose kind is no longer registered instead of crashing on save', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'Hi']],
        ['id' => 'ghost1', 'kind' => 'Ghost', 'active' => true, 'sort' => 1, 'data' => ['legacy' => 'value']],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'name' => ['en' => 'Renamed via save'],
            'block_blk1_heading' => 'Hi', 'block_blk1_active' => true, 'block_blk1_sort' => 0,
        ],
    )->assertRedirect()->assertSessionHasNoErrors();

    // The save must have actually run — not bounced off validation.
    expect($page->refresh()->getTranslation('name', 'en'))->toBe('Renamed via save');

    $ghost = collect($page->content)->firstWhere('id', 'ghost1');
    expect($ghost)->not->toBeNull()
        ->and($ghost['kind'])->toBe('Ghost')
        ->and($ghost['data'])->toBe(['legacy' => 'value']);
});

it('round-trips the rich-text body through save as a per-locale map', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $lexical = ['root' => ['type' => 'root', 'children' => []]];

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'body' => ['en' => $lexical]],
    )->assertRedirect();

    expect($page->refresh()->getTranslation('body', 'en'))->toBe($lexical);
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

    expect($sidebarFieldNames)->toContain('status', 'type', 'parent_id', 'sort_order', 'is_indexable');
});

it('puts status and is_indexable together in the sidebar Publication card', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $grid = findNodeByKind($form, 'grid');
    $sidebar = $grid['options']['children'][1];
    $publicationCard = $sidebar['options']['children'][0];

    $names = array_column($publicationCard['options']['children'], 'name');
    expect($names)->toBe(['status', 'is_indexable']);
});

it('renders the status bar as the first child of the form with a status badge', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $firstChild = $form['options']['children'][0];

    expect($firstChild['kind'])->toBe('flex')
        ->and($firstChild['options']['variant'])->toBe('card');

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
            'seo_focus_keyword' => 'widgets',
            'og_title' => 'OG title',
            'og_description' => 'OG description',
            'og_image' => '42',
            'is_indexable' => true,
        ],
    );

    $response->assertRedirect();
    $page->refresh();

    expect($page->getTranslation('name', 'en'))->toBe('About Updated')
        ->and($page->sort_order)->toBe(5)
        ->and($page->seo)->toBe([
            'title' => 'Meta title',
            'description' => 'Meta description',
            'focus_keyword' => 'widgets',
            'og_title' => 'OG title',
            'og_description' => 'OG description',
            'og_image' => '42',
        ]);
});

it('round-trips the new SEO keys back into the edit form via loadRow', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $session = test()->actingAs(makePageEditAdmin(), 'web');

    $session->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'seo_focus_keyword' => 'widgets',
            'og_title' => 'OG title',
            'og_description' => 'OG description',
            'og_image' => '42',
        ],
    )->assertRedirect();

    $pageEditor = $session->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.data.pageEditor');

    expect($pageEditor['seo_focus_keyword'])->toBe('widgets')
        ->and($pageEditor['og_title'])->toBe('OG title')
        ->and($pageEditor['og_description'])->toBe('OG description')
        ->and($pageEditor['og_image'])->toBe('42');
});

it('round-trips two blocks through save: data, active, and order are preserved', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'One', 'body' => 'First']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'Two', 'body' => 'Second']],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One Updated',
            'block_blk1_body' => 'First Updated',
            'block_blk1_active' => true,
            'block_blk1_sort' => 0,
            'block_blk2_heading' => 'Two',
            'block_blk2_body' => 'Second',
            'block_blk2_active' => false,
            'block_blk2_sort' => 1,
        ],
    )->assertRedirect();

    $content = $page->refresh()->content;

    expect($content)->toHaveCount(2)
        ->and($content[0]['id'])->toBe('blk1')
        ->and($content[0]['data'])->toBe(['heading' => 'One Updated', 'body' => 'First Updated'])
        ->and($content[0]['active'])->toBeTrue()
        ->and($content[0]['sort'])->toBe(0)
        ->and($content[1]['id'])->toBe('blk2')
        ->and($content[1]['data'])->toBe(['heading' => 'Two', 'body' => 'Second'])
        ->and($content[1]['active'])->toBeFalse()
        ->and($content[1]['sort'])->toBe(1);
});

it('reorders the envelope when block_{id}_sort is changed on save', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'One']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'Two']],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One',
            'block_blk1_active' => true,
            'block_blk1_sort' => 5,
            'block_blk2_heading' => 'Two',
            'block_blk2_active' => true,
            'block_blk2_sort' => 1,
        ],
    )->assertRedirect();

    $content = $page->refresh()->content;

    expect(array_column($content, 'id'))->toBe(['blk2', 'blk1'])
        ->and($content[0]['sort'])->toBe(1)
        ->and($content[1]['sort'])->toBe(5);
});

it('reads a legacy envelope without sort in its stored order, then assigns sort on save', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'One']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Two']],
    ]);

    expect($page->content[0])->not->toHaveKey('sort');

    $admin = makePageEditAdmin();
    $session = test()->actingAs($admin, 'web');

    $tabsJson = $session->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])->assertOk()->json();
    $form = findNodeByKind($tabsJson['props']['structure'], 'form');
    $tabsNode = findNodeByKind($form, 'tabs');
    $tabs = $tabsNode['options']['tabs'];
    $firstNames = [];
    collectFieldNames($tabs[2]['body'], $firstNames);
    expect($firstNames)->toContain('block_blk1_sort');

    $session->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One',
            'block_blk1_active' => true,
            'block_blk1_sort' => 0,
            'block_blk2_heading' => 'Two',
            'block_blk2_active' => true,
            'block_blk2_sort' => 1,
        ],
    )->assertRedirect();

    $content = $page->refresh()->content;

    expect(array_column($content, 'id'))->toBe(['blk1', 'blk2'])
        ->and($content[0]['sort'])->toBe(0)
        ->and($content[1]['sort'])->toBe(1);
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
        ->and($page->content[0]['sort'])->toBe(0)
        ->and($page->getTranslation('name', 'en'))->toBe('Renamed');

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect', 'notify');
});

it('appends a new block after the highest existing sort', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 3, 'data' => ['heading' => 'One']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'sort' => 7, 'data' => ['heading' => 'Two']],
    ]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockAdd_Simple",
        ['payload' => ['form' => [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One',
            'block_blk1_active' => true,
            'block_blk1_sort' => 3,
            'block_blk2_heading' => 'Two',
            'block_blk2_active' => true,
            'block_blk2_sort' => 7,
        ]]],
    );

    $response->assertOk();
    $content = $page->refresh()->content;

    expect($content)->toHaveCount(3)
        ->and($content[2]['sort'])->toBe(8)
        ->and($content[2]['active'])->toBeTrue();
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
    $names = array_column($statusBar['options']['children'], 'name');

    expect($names)->toContain('pageEditView', 'saveTop');
});

it('hides delete from the form footer for system pages', function (): void {
    $system = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Home'], 'is_system' => true]);

    $form = findNodeByKind(pageEditStructure($system->id)['props']['structure'], 'form');
    $footerActions = footerActionNames($form);

    expect($footerActions)->toBe(['cancel', 'save']);
});

it('keeps delete in the form footer for regular pages', function (): void {
    $regular = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($regular->id)['props']['structure'], 'form');
    $footerActions = footerActionNames($form);

    expect($footerActions)->toBe(['pageEditDelete', 'cancel', 'save']);
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
        'seo_focus_keyword' => '',
        'og_title' => '',
        'og_description' => '',
        'og_image' => '',
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

/**
 * Recursively collects every field `name` under a node, regardless of
 * nesting depth — the active/sort pair lives inside a nested flex row, so a
 * flat array_column() over the top-level children misses them.
 *
 * @param  array<string, mixed>  $node
 * @param  list<string>  $names
 */
function collectFieldNames(array $node, array &$names): void
{
    if (isset($node['name']) && is_string($node['name'])) {
        $names[] = $node['name'];
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            collectFieldNames($child, $names);
        }
    }
}
