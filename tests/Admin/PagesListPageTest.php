<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\PageFormOptions;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);
    $this->actingAs($user, 'web');
});

/** @return array<string, mixed> First node of the given kind in the page structure. */
function pagesTableNode(): array
{
    $structure = test()->get('/admin/pages', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $found = null;
    $walk = function (array $node) use (&$walk, &$found): void {
        if (($node['kind'] ?? null) === 'table') {
            $found ??= $node;

            return;
        }
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            $walk($child);
        }
    };
    $walk($structure);

    expect($found)->not->toBeNull();

    return $found;
}

it('renders the divotek-style pages list wire shape', function (): void {
    $node = pagesTableNode();
    $opts = $node['options'];

    // Tabs: All / Categories / Pages / Articles, each with a subtitle.
    $tabs = array_column($opts['tabs'], 'label', 'name');
    expect($tabs)->toBe([
        'all' => 'All',
        'categories' => 'Categories',
        'pages' => 'Pages',
        'articles' => 'Articles',
    ])->and(array_column($opts['tabs'], 'description'))->toHaveCount(4);

    // Columns: Title (emphasized) / Type (muted+uppercase) / Parent / Status badge / Updated date.
    $cols = array_column($opts['columns'], null, 'name');
    expect(array_keys($cols))->toBe(['name', 'type', 'parent_name', 'status', 'updated_at'])
        ->and($cols['name']['emphasized'] ?? null)->toBeTrue()
        ->and($cols['type']['muted'] ?? null)->toBeTrue()
        ->and($cols['type']['uppercase'] ?? null)->toBeTrue()
        ->and($cols['status']['kind'] ?? null)->toBe('badge')
        ->and($cols['status']['badge']['colors'])->toBe(['Published' => 'success', 'Draft' => 'warning'])
        ->and($cols['updated_at']['kind'] ?? null)->toBe('date');

    // Toolbar (search + column toggle) visible (not explicitly disabled); the whole row links to the edit page.
    expect($opts['searchInput'] ?? null)->not->toBeFalse()
        ->and($opts['columnToggle'] ?? null)->not->toBeFalse()
        ->and($opts['recordUrl'] ?? null)->toBeTrue();

    // Row actions: only the external view-on-site link (quick-edit removed; the row itself links to the edit page).
    $actions = array_column($opts['rowActions'], null, 'name');
    expect(array_keys($actions))->toBe(['pageView'])
        ->and($actions['pageView']['options']['spec']['type'] ?? $actions['pageView']['spec']['type'] ?? null)->not->toBeNull();
});

it('links each row to its dedicated edit page', function (): void {
    $page = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['_recordUrl'])->toEndWith("/admin/pages/{$page->id}/edit");
});

it('scopes the tab queries by page type and parent', function (): void {
    $category = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    Page::create(['type' => 'article', 'parent_id' => $category->id, 'status' => 'draft', 'name' => ['en' => 'Post']]);

    $rows = fn (string $tab): array => $this->getJson("/admin/pages/tables/pages?tab={$tab}")
        ->assertOk()
        ->json('data.data');

    expect(array_column($rows('all'), 'id'))->toHaveCount(3)
        ->and(array_column($rows('categories'), 'type'))->toBe(['Category'])
        ->and(array_column($rows('pages'), 'type'))->toBe(['Page'])
        ->and(array_column($rows('articles'), 'type'))->toBe(['Article']);
});

it('renders the toolbar with a search input', function (): void {
    $node = pagesTableNode();
    $opts = $node['options'];

    // No explicit toolbar()/searchInput() call → back-compat default: renders
    // as long as searchable fields are declared (see TableToolbar client logic).
    expect($opts['searchInput'] ?? null)->not->toBeFalse()
        ->and($opts['searchable'] ?? [])->not->toBeEmpty();
});

it('searches the list by page title, matching only rows whose title contains the term', function (): void {
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About Us']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'Contact']]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all&search=About')
        ->assertOk()
        ->json('data.data');

    expect(array_column($rows, 'name'))->toBe(['About Us']);
});

it('projects parent name, formatted status, and slug path into list rows', function (): void {
    $category = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog']]);
    Page::create(['type' => 'article', 'parent_id' => $category->id, 'status' => 'draft', 'name' => ['en' => 'Post']]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=articles')->assertOk()->json('data.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['parent_name'])->toBe('Blog')
        ->and($rows[0]['status'])->toBe('Draft')
        ->and($rows[0]['path'])->toBe('blog/post');
});

it('offers no empty-string parent option (Radix SelectItem crash guard)', function (): void {
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);

    $values = array_column(PageFormOptions::parents(), 'value');

    expect($values)->not->toContain('')
        ->and($values[0])->toBe(PageFormOptions::NO_PARENT);
});

it('only offers categories as parent options', function (): void {
    $category = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    Page::create(['type' => 'article', 'parent_id' => $category->id, 'status' => 'draft', 'name' => ['en' => 'Post']]);

    $options = PageFormOptions::parents();

    expect(array_column($options, 'value'))->toBe([PageFormOptions::NO_PARENT, (string) $category->id])
        ->and(array_column($options, 'label')[1])->toBe('Blog');
});

it('sorts the list as a tree: each child directly after its parent, siblings by created_at ascending', function (): void {
    $blog = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog'], 'created_at' => '2026-07-01 00:00:00']);
    $news = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'News'], 'created_at' => '2026-07-02 00:00:00']);
    $blogSub1 = Page::create(['type' => 'article', 'parent_id' => $blog->id, 'status' => 'published', 'name' => ['en' => 'BlogSub1'], 'created_at' => '2026-07-03 00:00:00']);
    $newsSub = Page::create(['type' => 'article', 'parent_id' => $news->id, 'status' => 'published', 'name' => ['en' => 'NewsSub'], 'created_at' => '2026-07-04 00:00:00']);
    $blogSub2 = Page::create(['type' => 'article', 'parent_id' => $blog->id, 'status' => 'published', 'name' => ['en' => 'BlogSub2'], 'created_at' => '2026-07-05 00:00:00']);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');

    expect(array_column($rows, 'id'))->toBe([
        $blog->id,
        $blogSub1->id,
        $blogSub2->id,
        $news->id,
        $newsSub->id,
    ]);
});
