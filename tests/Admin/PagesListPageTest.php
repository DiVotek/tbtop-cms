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

    // Toolbar (search + column toggle) hidden; the whole row links to the edit page.
    expect($opts['searchInput'] ?? null)->toBeFalse()
        ->and($opts['columnToggle'] ?? null)->toBeFalse()
        ->and($opts['recordUrl'] ?? null)->toBeTrue();

    // Row actions: icon-only quick edit + external view-on-site link.
    $actions = array_column($opts['rowActions'], null, 'name');
    expect(array_keys($actions))->toBe(['pageQuickEdit', 'pageView'])
        ->and($actions['pageQuickEdit']['options']['spec']['type'] ?? $actions['pageQuickEdit']['spec']['type'] ?? null)->toBe('modal')
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
