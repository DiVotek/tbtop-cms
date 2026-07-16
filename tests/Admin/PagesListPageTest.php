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

    // Tabs: All / Categories / Pages / Articles / Deleted, each with a subtitle.
    $tabs = array_column($opts['tabs'], 'label', 'name');
    expect($tabs)->toBe([
        'all' => 'All',
        'categories' => 'Categories',
        'pages' => 'Pages',
        'articles' => 'Articles',
        'deleted' => 'Deleted',
    ])->and(array_column($opts['tabs'], 'description'))->toHaveCount(5);

    // Columns: Title (emphasized) / Type (muted+uppercase) / Parent / Status badge / Updated date / deleted_at (hidden helper).
    $cols = array_column($opts['columns'], null, 'name');
    expect(array_keys($cols))->toBe(['name', 'type', 'parent_name', 'status', 'updated_at', 'deleted_at'])
        ->and($cols['name']['emphasized'] ?? null)->toBeTrue()
        ->and($cols['type']['muted'] ?? null)->toBeTrue()
        ->and($cols['type']['uppercase'] ?? null)->toBeTrue()
        ->and($cols['status']['kind'] ?? null)->toBe('badge')
        ->and($cols['status']['badge']['colors'])->toBe(['Published' => 'success', 'Draft' => 'warning'])
        ->and($cols['updated_at']['kind'] ?? null)->toBe('date')
        ->and($cols['deleted_at']['hiddenByDefault'] ?? null)->toBeTrue();

    // Toolbar (search + column toggle) visible (not explicitly disabled); the whole row links to the edit page.
    expect($opts['searchInput'] ?? null)->not->toBeFalse()
        ->and($opts['columnToggle'] ?? null)->not->toBeFalse()
        ->and($opts['recordUrl'] ?? null)->toBeTrue();

    // Row actions: view-on-site link, restore, delete permanently.
    $actions = array_column($opts['rowActions'], null, 'name');
    expect(array_keys($actions))->toBe(['pageView', 'pageRestore', 'pageForceDelete'])
        ->and($actions['pageView']['options']['spec']['type'] ?? $actions['pageView']['spec']['type'] ?? null)->not->toBeNull();
});

it('hides restore/delete-permanently on active rows and view-on-site on trashed rows via hiddenIf', function (): void {
    $node = pagesTableNode();
    $actions = array_column($node['options']['rowActions'], null, 'name');

    // Active rows: deleted_at is empty → restore/forceDelete hidden, view-on-site shown.
    expect($actions['pageRestore']['meta']['hiddenIf'])->toBe(['op' => 'empty', 'field' => 'deleted_at'])
        ->and($actions['pageForceDelete']['meta']['hiddenIf'])->toBe(['op' => 'empty', 'field' => 'deleted_at'])
        ->and($actions['pageView']['meta']['hiddenIf'])->toBe(['op' => 'notEmpty', 'field' => 'deleted_at']);
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

it('lists only trashed pages in the Deleted tab and excludes them from the other four', function (): void {
    $active = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'Active']]);
    $trashed = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'Trashed']]);
    $trashed->delete();

    $rows = fn (string $tab): array => $this->getJson("/admin/pages/tables/pages?tab={$tab}")
        ->assertOk()
        ->json('data.data');

    expect(array_column($rows('deleted'), 'id'))->toBe([$trashed->id])
        ->and(array_column($rows('all'), 'id'))->toBe([$active->id])
        ->and(array_column($rows('pages'), 'id'))->toBe([$active->id]);
});

it('does not link a trashed row to an edit page (recordUrl resolves to null)', function (): void {
    $page = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    $page->delete();

    $rows = $this->getJson('/admin/pages/tables/pages?tab=deleted')->assertOk()->json('data.data');

    expect($rows)->toHaveCount(1)
        ->and(array_key_exists('_recordUrl', $rows[0]))->toBeTrue()
        ->and($rows[0]['_recordUrl'])->toBeNull();
});

it('finds trashed pages by search within the Deleted tab (search stays qualified against the self-join)', function (): void {
    $trashed = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'Findable']]);
    $trashed->delete();
    $other = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'Other']]);
    $other->delete();

    $rows = $this->getJson('/admin/pages/tables/pages?tab=deleted&search=Findable')
        ->assertOk()
        ->json('data.data');

    expect(array_column($rows, 'id'))->toBe([$trashed->id]);
});

it('restores a trashed page via the row action, re-creating its slug', function (): void {
    $page = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    $page->delete();

    $response = $this->postJson('/admin/pages/actions/pageRestore', ['payload' => ['row' => ['id' => $page->id]]]);

    $response->assertOk();
    expect($page->fresh()->trashed())->toBeFalse()
        ->and($page->fresh()->pathFor('en'))->toBe('about');

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('notify', 'refreshTable');
});

it('blocks the restore row action with a notify effect when the parent is still trashed', function (): void {
    $category = Page::factory()->category()->create(['status' => 'published', 'name' => ['en' => 'Blog']]);
    $article = Page::factory()->article()->create([
        'status' => 'published',
        'parent_id' => $category->id,
        'name' => ['en' => 'Post'],
    ]);
    $article->delete();
    $category->delete();

    $response = $this->postJson('/admin/pages/actions/pageRestore', ['payload' => ['row' => ['id' => $article->id]]]);

    $response->assertOk();
    expect($article->fresh()->trashed())->toBeTrue();

    $notify = collect($response->json('effects'))->firstWhere('kind', 'notify');
    expect($notify['level'] ?? null)->toBe('error');
});

it('force-deletes a trashed page permanently via the row action', function (): void {
    $page = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    $page->delete();

    $response = $this->postJson('/admin/pages/actions/pageForceDelete', ['payload' => ['row' => ['id' => $page->id]]]);

    $response->assertOk();
    expect(Page::withTrashed()->find($page->id))->toBeNull();

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('notify', 'refreshTable');
});

it('blocks force-delete permanently for a system page via the row action', function (): void {
    // A system page can never be soft-deleted in the first place (deleting()
    // blocks it before it becomes trashed) — force-delete is called directly
    // on the active row action, exercising the same isForceDeleting() guard.
    $page = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'Home'], 'is_system' => true]);

    $response = $this->postJson('/admin/pages/actions/pageForceDelete', ['payload' => ['row' => ['id' => $page->id]]]);

    $response->assertOk();
    expect(Page::withTrashed()->find($page->id))->not->toBeNull();

    $notify = collect($response->json('effects'))->firstWhere('kind', 'notify');
    expect($notify['level'] ?? null)->toBe('error');
});

it('blocks force-delete for a parent whose trashed children still reference it', function (): void {
    $category = Page::factory()->category()->create(['status' => 'published', 'name' => ['en' => 'Blog']]);
    $article = Page::factory()->article()->create([
        'status' => 'published',
        'parent_id' => $category->id,
        'name' => ['en' => 'Post'],
    ]);
    $article->delete();
    $category->delete();

    $response = $this->postJson('/admin/pages/actions/pageForceDelete', ['payload' => ['row' => ['id' => $category->id]]]);

    $response->assertOk();
    expect(Page::withTrashed()->find($category->id))->not->toBeNull();

    $notify = collect($response->json('effects'))->firstWhere('kind', 'notify');
    expect($notify['level'] ?? null)->toBe('error');
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
