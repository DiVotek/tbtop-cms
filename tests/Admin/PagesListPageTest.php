<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Enums\SeoState;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Types\BasePageType;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\SectionType;
use Tbtop\Cms\Tests\Fixtures\SourcedType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
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
    Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    $node = pagesTableNode();
    $opts = $node['options'];

    // Tabs: All, one per type a live page has (registry order), Deleted — each with a subtitle.
    $tabs = array_column($opts['tabs'], 'label', 'name');
    expect($tabs)->toBe([
        'all' => 'All',
        'page' => 'Page',
        'category' => 'Category',
        'deleted' => 'Deleted',
    ])->and(array_column($opts['tabs'], 'description'))->toHaveCount(4);

    // Columns: Title (emphasized) / Type (muted+uppercase) / Parent / Status badge (computed display_state) / SEO badge (computed seo_state) / Updated date / Updated by (hidden) / deleted_at and path (hidden helpers).
    $cols = array_column($opts['columns'], null, 'name');
    expect(array_keys($cols))->toBe(['name', 'type', 'parent_name', 'display_state', 'seo_state', 'updated_at', 'updated_by_name', 'deleted_at', 'path'])
        ->and($cols['name']['emphasized'] ?? null)->toBeTrue()
        ->and($cols['type']['muted'] ?? null)->toBeTrue()
        ->and($cols['type']['uppercase'] ?? null)->toBeTrue()
        ->and($cols['display_state']['kind'] ?? null)->toBe('badge')
        ->and($cols['display_state']['badge']['colors'])->toBe([
            'Published' => 'success',
            'Draft' => 'warning',
            'Scheduled' => 'gray',
            'Expired' => 'gray',
        ])
        ->and($cols['seo_state']['kind'] ?? null)->toBe('badge')
        ->and($cols['seo_state']['badge']['colors'])->toBe([
            'Missing' => 'danger',
            'Needs work' => 'warning',
            'Good' => 'success',
        ])
        ->and($cols['updated_at']['kind'] ?? null)->toBe('date')
        ->and($cols['updated_by_name']['hiddenByDefault'] ?? null)->toBeTrue()
        ->and($cols['deleted_at']['hiddenByDefault'] ?? null)->toBeTrue();

    // Toolbar (search + column toggle) visible (not explicitly disabled); the whole row links to the edit page.
    expect($opts['searchInput'] ?? null)->not->toBeFalse()
        ->and($opts['columnToggle'] ?? null)->not->toBeFalse()
        ->and($opts['recordUrl'] ?? null)->toBeTrue();

    // Row actions: view-on-site link, duplicate, restore, delete permanently.
    $actions = array_column($opts['rowActions'], null, 'name');
    expect(array_keys($actions))->toBe(['pageView', 'pageDuplicate', 'pageRestore', 'pageForceDelete'])
        ->and($actions['pageView']['options']['spec']['type'] ?? $actions['pageView']['spec']['type'] ?? null)->not->toBeNull();
});

it('hides restore/delete-permanently/duplicate on active rows and view-on-site on trashed rows via hiddenIf', function (): void {
    $node = pagesTableNode();
    $actions = array_column($node['options']['rowActions'], null, 'name');

    // Active rows: deleted_at is empty → restore/forceDelete hidden, view-on-site shown.
    expect($actions['pageRestore']['meta']['hiddenIf'])->toBe(['op' => 'empty', 'field' => 'deleted_at'])
        ->and($actions['pageForceDelete']['meta']['hiddenIf'])->toBe(['op' => 'empty', 'field' => 'deleted_at'])
        ->and($actions['pageView']['meta']['hiddenIf'])->toBe(['op' => 'any', 'conds' => [
            ['op' => 'notEmpty', 'field' => 'deleted_at'],
            ['op' => 'eq', 'field' => 'path', 'value' => null],
        ]])
        ->and($actions['pageDuplicate']['meta']['hiddenIf'])->toBe(['op' => 'any', 'conds' => [
            ['op' => 'notEmpty', 'field' => 'deleted_at'],
            ['op' => 'truthy', 'field' => 'source_backed'],
        ]]);
});

it('hides view-on-site for an error page, which has no path, but not for a page at the root path', function (): void {
    Page::factory()->create(['is_system' => true, 'error_class' => ErrorClass::ClientError, 'status' => PageStatus::Draft, 'name' => ['en' => 'Not found']]);
    $about = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    PageSlug::query()->where('page_id', $about->id)->update(['path' => '']);

    $rows = collect($this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data'))->keyBy('name');
    $hiddenIf = array_column(pagesTableNode()['options']['rowActions'], null, 'name')['pageView']['meta']['hiddenIf'];

    // Evaluates the any(...) the way the client does for these two ops ('' and null differ under eq).
    $hidden = static fn (array $row): bool => collect($hiddenIf['conds'])->contains(static fn (array $c): bool => match ($c['op']) {
        'notEmpty' => ! in_array($row[$c['field']] ?? null, [null, ''], true),
        'eq' => array_key_exists($c['field'], $row) && $row[$c['field']] === $c['value'],
    });

    expect($hidden($rows['Not found']))->toBeTrue()
        ->and($hidden($rows['About']))->toBeFalse();
});

it('links each row to its dedicated edit page', function (): void {
    $page = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['_recordUrl'])->toEndWith("/admin/pages/{$page->id}/edit");
});

it('opts every tab into a count badge, including Deleted', function (): void {
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    $node = pagesTableNode();

    $counts = array_column($node['options']['tabs'], 'count', 'name');
    expect($counts)->toBe([
        'all' => true,
        'page' => true,
        'deleted' => true,
    ]);
});

it('returns per-tab counts scoped by each tab\'s own query, ignoring the active tab filter', function (): void {
    $category = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    $trashed = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'Old']]);
    $trashed->delete();

    $counts = $this->getJson('/admin/pages/tables/pages?tab=category')
        ->assertOk()
        ->json('data.tabCounts');

    expect($counts)->toBe([
        'all' => 2,
        'page' => 1,
        'category' => 1,
        'deleted' => 1,
    ]);
});

it('scopes the tab queries by page type and parent', function (): void {
    $category = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    Page::create(['type' => 'article', 'parent_id' => $category->id, 'status' => 'draft', 'name' => ['en' => 'Post']]);

    $rows = fn (string $tab): array => $this->getJson("/admin/pages/tables/pages?tab={$tab}")
        ->assertOk()
        ->json('data.data');

    expect(array_column($rows('all'), 'id'))->toHaveCount(3)
        ->and(array_column($rows('category'), 'type'))->toBe(['Category'])
        ->and(array_column($rows('page'), 'type'))->toBe(['Page'])
        ->and(array_column($rows('article'), 'type'))->toBe(['Article']);
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
        ->and(array_column($rows('page'), 'id'))->toBe([$active->id]);
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

it('blocks the restore row action with a notify effect when the parent no longer allows the page\'s type', function (): void {
    $category = Page::factory()->category()->create(['status' => 'published', 'name' => ['en' => 'Blog']]);
    $article = Page::factory()->article()->create([
        'status' => 'published',
        'parent_id' => $category->id,
        'name' => ['en' => 'Post'],
    ]);
    $article->delete();
    $category->update(['type' => 'page']);

    $response = $this->postJson('/admin/pages/actions/pageRestore', ['payload' => ['row' => ['id' => $article->id]]]);

    $response->assertOk();
    expect($article->fresh()->trashed())->toBeTrue();

    $notify = collect($response->json('effects'))->firstWhere('kind', 'notify');
    expect($notify['level'] ?? null)->toBe('error');
});

it('duplicates a page via the row action, creating a draft copy next to the original', function (): void {
    $category = Page::factory()->category()->create(['name' => ['en' => 'Blog']]);
    $page = Page::factory()->article()->create([
        'parent_id' => $category->id,
        'status' => 'published',
        'name' => ['en' => 'About'],
    ]);

    $response = $this->postJson('/admin/pages/actions/pageDuplicate', ['payload' => ['row' => ['id' => $page->id]]]);

    $response->assertOk();

    $copy = Page::query()->where('id', '!=', $page->id)->where('parent_id', $category->id)->first();
    expect($copy)->not->toBeNull()
        ->and($copy->getTranslation('name', 'en', false))->toBe('About (copy)')
        ->and($copy->status->value)->toBe('draft');

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('notify', 'refreshTable');
});

it('reports an error instead of failing when duplicating a page whose type is no longer registered', function (): void {
    $page = Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    Page::query()->whereKey($page->id)->toBase()->update(['type' => 'retired']);

    $response = $this->postJson('/admin/pages/actions/pageDuplicate', ['payload' => ['row' => ['id' => $page->id]]]);

    $response->assertOk();
    expect(Page::query()->count())->toBe(1);

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

    $rows = $this->getJson('/admin/pages/tables/pages?tab=article')->assertOk()->json('data.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['parent_name'])->toBe('Blog')
        ->and($rows[0]['display_state'])->toBe('Draft')
        ->and($rows[0]['path'])->toBe('blog/post');
});

it('only offers categories as parent picker candidates', function (): void {
    $category = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    Page::create(['type' => 'article', 'parent_id' => $category->id, 'status' => 'draft', 'name' => ['en' => 'Post']]);

    $ids = PageForm::parentQuery()->pluck('id')->all();

    expect($ids)->toBe([$category->id]);
});

it('excludes the edited page and its descendants from the parent picker query', function (): void {
    $grandparent = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'A']]);
    $parent = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'B'], 'parent_id' => $grandparent->id]);
    $child = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'C'], 'parent_id' => $parent->id]);
    $unrelated = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'D']]);

    $ids = PageForm::parentQuery($parent)->pluck('id')->all();

    expect($ids)->toContain($grandparent->id)
        ->and($ids)->toContain($unrelated->id)
        ->and($ids)->not->toContain($parent->id)
        ->and($ids)->not->toContain($child->id);
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

it('renders seo_state = fail (label: Missing) for a page missing every title source', function (): void {
    $page = Page::create(['type' => 'page', 'status' => 'draft']);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');
    $row = collect($rows)->firstWhere('id', $page->id);

    expect($row['seo_state'])->toBe('Missing');
});

it('explains the badge in a tooltip listing what the page is short of', function (): void {
    $page = Page::create(['type' => 'page', 'status' => 'draft', 'is_indexable' => false]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');
    $row = collect($rows)->firstWhere('id', $page->id);

    expect($row['seo_state'])->toBe('Missing')
        ->and($row['_tooltips']['seo_state'])
        ->toBe("No name and no title\nNo summary\nExcluded from indexing");
});

it('names only the reason that actually applies', function (): void {
    // A page with a name but no summary is the common warn case; the tooltip
    // must not also claim the title is missing.
    $page = Page::create([
        'type' => 'page',
        'status' => 'draft',
        'name' => ['en' => 'About'],
        'is_indexable' => true,
    ]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');
    $row = collect($rows)->firstWhere('id', $page->id);

    expect($row['seo_state'])->toBe('Needs work')
        ->and($row['_tooltips']['seo_state'])->toBe('No summary');
});

it('does not claim a page has no title when it carries a meta title', function (): void {
    // The badge's SQL cannot reach seo.title (nested JSON, three dialects —
    // see seoStateSql()), so such a page still badges red. The tooltip must
    // not compound that by asserting something false: no reason is honest
    // here, a wrong reason is not.
    $page = Page::create([
        'type' => 'page',
        'status' => 'published',
        'name' => [],
        'title' => [],
        'summary' => ['en' => 'A summary.'],
        'seo' => ['title' => ['en' => 'A hand-written meta title']],
        'is_indexable' => true,
    ]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');
    $row = collect($rows)->firstWhere('id', $page->id);

    expect($row['seo_state'])->toBe('Missing')
        ->and($row['_tooltips']['seo_state'] ?? null)->toBeNull();
});

it('counts a whitespace-only value as content, exactly as the badge does', function (): void {
    // The SQL treats `value <> ''` as content and documents that as a
    // deliberate false pass. Trimming in PHP would badge this page Good while
    // telling it "No summary" — the tooltip contradicting the badge.
    $page = Page::create([
        'type' => 'page',
        'status' => 'published',
        'name' => ['en' => 'About'],
        'summary' => ['en' => '   '],
        'is_indexable' => true,
    ]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');
    $row = collect($rows)->firstWhere('id', $page->id);

    expect($row['seo_state'])->toBe('Good')
        ->and($row['_tooltips']['seo_state'] ?? null)->toBeNull();
});

it('keeps the tooltip and the badge in step across every badge state', function (): void {
    // The tooltip is derived in PHP while the badge comes from SQL — two
    // codepaths answering one question, which is what makes them able to
    // drift. This pins the rule rather than the wording: a green badge means
    // no reasons, anything else means at least one.
    //
    // The one documented exception is a page carrying only seo.title, which
    // the badge's SQL cannot see — covered by its own test above, and not
    // created here.
    Page::create(['type' => 'page', 'status' => 'draft']);
    Page::create(['type' => 'page', 'status' => 'draft', 'name' => ['en' => 'Warn me']]);
    Page::create([
        'type' => 'page',
        'status' => 'published',
        'name' => ['en' => 'All good'],
        'summary' => ['en' => 'A summary.'],
        'is_indexable' => true,
    ]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');

    foreach ($rows as $row) {
        $tooltip = $row['_tooltips']['seo_state'] ?? '';

        expect($row['seo_state'] === 'Good')->toBe($tooltip === '');
    }
});

it('carries no tooltip on a page with nothing wrong', function (): void {
    $page = Page::create([
        'type' => 'page',
        'status' => 'published',
        'name' => ['en' => 'About'],
        'summary' => ['en' => 'What we do.'],
        'is_indexable' => true,
    ]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');
    $row = collect($rows)->firstWhere('id', $page->id);

    expect($row['seo_state'])->toBe('Good')
        ->and($row['_tooltips']['seo_state'] ?? null)->toBeNull();
});

it('treats an emptied translatable column as missing, not as content', function (): void {
    // Clearing a translatable field in the form does not null the column —
    // the cast stores `[]`, which a plain IS NULL test would read as "has a
    // title source" and wrongly badge Good.
    $page = Page::create(['type' => 'page', 'status' => 'draft', 'name' => [], 'title' => []]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');

    expect(collect($rows)->firstWhere('id', $page->id)['seo_state'])->toBe('Missing');
});

it('treats a translatable column holding only blank locale values as missing', function (): void {
    // Clearing a field that HAD content is the harsher case: the locale key
    // survives with a blank value, so the column stores `{"en":""}` — which a
    // comparison against `[]`/`{}` reads as populated and wrongly badges Good.
    $page = Page::create(['type' => 'page', 'status' => 'draft']);
    // Written past the model: the translatable cast would re-encode a JSON
    // string into `{"en":"{...}"}`, which is real content, not the blank map
    // this test is about.
    DB::table('tbtop_pages')->where('id', $page->id)->update([
        'name' => '{"en":""}',
        'title' => '{"en":"","uk":""}',
    ]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');

    expect(collect($rows)->firstWhere('id', $page->id)['seo_state'])->toBe('Missing');
});

it('counts content in a non-default locale as a title source', function (): void {
    // The mirror of the check above: erasing the locale keys must not erase
    // the values next to them.
    $page = Page::create([
        'type' => 'page',
        'status' => 'draft',
        'name' => ['en' => '', 'uk' => 'Про нас'],
    ]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');

    expect(collect($rows)->firstWhere('id', $page->id)['seo_state'])->not->toBe('Missing');
});

it('warns when a page has a title source but its summary was emptied', function (): void {
    $page = Page::create([
        'type' => 'page',
        'status' => 'published',
        'name' => ['en' => 'About'],
        'summary' => [],
        'is_indexable' => true,
    ]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');

    expect(collect($rows)->firstWhere('id', $page->id)['seo_state'])->toBe('Needs work');
});

it('renders seo_state = pass (label: Good) for a fully-populated page', function (): void {
    $page = Page::create([
        'type' => 'page',
        'status' => 'published',
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About us'],
        'summary' => ['en' => 'A short summary of who we are.'],
        'is_indexable' => true,
    ]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');
    $row = collect($rows)->firstWhere('id', $page->id);

    expect($row['seo_state'])->toBe('Good');
});

it('runs the seo_state query without error on the test driver', function (): void {
    Page::factory()->count(3)->create();

    $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk();
});

it('gives a host type and an unregistered type their own tab', function (): void {
    PageTypeRegistry::extend(fn (): array => [SectionType::class]);
    Page::create(['type' => 'section', 'status' => 'published', 'name' => ['en' => 'Work']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);
    DB::table((new Page)->getTable())->insert(['type' => 'legacy_promo', 'status' => 'draft', 'name' => json_encode(['en' => 'Promo']), 'created_at' => now(), 'updated_at' => now()]);
    $trashed = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Gone']]);
    $trashed->delete();

    $tabs = array_column(pagesTableNode()['options']['tabs'], 'label', 'name');
    $names = fn (string $tab): array => array_column($this->getJson("/admin/pages/tables/pages?tab={$tab}")->assertOk()->json('data.data'), 'name');

    expect($tabs)->toBe(['all' => 'All', 'page' => 'Page', 'section' => 'Section', 'legacy_promo' => 'legacy_promo', 'deleted' => 'Deleted'])
        ->and($names('section'))->toBe(['Work'])
        ->and($names('legacy_promo'))->toBe(['Promo']);
});

it('labels the type column with the type label the tabs use', function (): void {
    $caseStudy = new class extends BasePageType
    {
        public function key(): string
        {
            return 'case_study';
        }

        #[Override]
        public function label(): string
        {
            return 'Case study';
        }
    };
    PageTypeRegistry::extend(fn (): array => [$caseStudy::class]);
    Page::create(['type' => 'case_study', 'status' => 'published', 'name' => ['en' => 'Acme']]);

    $tabs = array_column(pagesTableNode()['options']['tabs'], 'label', 'name');
    $rows = $this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data');

    expect($tabs['case_study'])->toBe('Case study')
        ->and($rows[0]['type'])->toBe('Case study');
});

it('flags source-backed rows so the duplicate action is hidden for them', function (): void {
    config()->set('tbtop-cms.pages.types', [SourcedType::class]);
    Page::create(['type' => 'sourced', 'source_id' => 'thing-1', 'status' => 'published', 'name' => ['en' => 'Thing']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);

    $rows = collect($this->getJson('/admin/pages/tables/pages?tab=all')->assertOk()->json('data.data'))->keyBy('name');

    expect((bool) $rows['Thing']['source_backed'])->toBeTrue()
        ->and((bool) ($rows['About']['source_backed'] ?? false))->toBeFalse();
});

it('reports an error instead of duplicating a source-backed page via the row action', function (): void {
    config()->set('tbtop-cms.pages.types', [SourcedType::class]);
    $page = Page::create(['type' => 'sourced', 'source_id' => 'thing-1', 'status' => 'published', 'name' => ['en' => 'Thing']]);

    $response = $this->postJson('/admin/pages/actions/pageDuplicate', ['payload' => ['row' => ['id' => $page->id]]]);

    $response->assertOk();
    expect(Page::query()->count())->toBe(1)
        ->and(collect($response->json('effects'))->firstWhere('kind', 'notify')['level'] ?? null)->toBe('error');
});

it('declares the parent, publication status and SEO filters, and no subtype filter', function (): void {
    $filters = pagesTableNode()['options']['filters'];

    expect(array_column($filters, 'name'))->toBe(['parent_id', 'display_state', 'seo_state']);
});

it('offers only pages that have children as parent filter options', function (): void {
    $blog = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog']]);
    Page::create(['type' => 'article', 'parent_id' => $blog->id, 'status' => 'published', 'name' => ['en' => 'Post']]);
    Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Empty']]);

    $filter = collect(pagesTableNode()['options']['filters'])->firstWhere('name', 'parent_id');

    expect(array_column($filter['options']['options'], 'label'))->toBe(['Blog']);
});

it('filters the list to the children of the chosen parent', function (): void {
    $blog = Page::create(['type' => 'category', 'status' => 'published', 'name' => ['en' => 'Blog']]);
    $post = Page::create(['type' => 'article', 'parent_id' => $blog->id, 'status' => 'published', 'name' => ['en' => 'Post']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'About']]);

    $rows = $this->getJson("/admin/pages/tables/pages?tab=all&filters[parent_id]={$blog->id}")->assertOk()->json('data.data');

    expect(array_column($rows, 'id'))->toBe([$post->id]);
});

it('filters the list by publication status using the computed display state', function (): void {
    Page::create(['type' => 'page', 'status' => 'draft', 'name' => ['en' => 'Draft one']]);
    Page::create(['type' => 'page', 'status' => 'published', 'published_at' => now()->addDay(), 'name' => ['en' => 'Scheduled one']]);
    Page::create(['type' => 'page', 'status' => 'published', 'unpublish_at' => now()->subDay(), 'name' => ['en' => 'Expired one']]);
    Page::create(['type' => 'page', 'status' => 'published', 'name' => ['en' => 'Live one']]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all&filters[display_state]=scheduled')->assertOk()->json('data.data');

    expect(array_column($rows, 'name'))->toBe(['Scheduled one']);
});

it('keeps a live page with a future unpublish date out of the expired filter', function (): void {
    Page::create(['type' => 'page', 'status' => 'published', 'unpublish_at' => now()->addDay(), 'name' => ['en' => 'Live one']]);
    Page::create(['type' => 'page', 'status' => 'published', 'unpublish_at' => now()->subDay(), 'name' => ['en' => 'Expired one']]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all&filters[display_state]=expired')->assertOk()->json('data.data');

    expect(array_column($rows, 'name'))->toBe(['Expired one']);
});

it('filters the list by SEO state using the computed badge rank', function (): void {
    $blank = Page::create(['type' => 'page', 'status' => 'draft']);
    Page::create(['type' => 'page', 'status' => 'draft', 'name' => ['en' => 'Titled'], 'summary' => ['en' => 'Summary']]);

    $rows = $this->getJson('/admin/pages/tables/pages?tab=all&filters[seo_state]=' . SeoState::Fail->value)
        ->assertOk()
        ->json('data.data');

    expect(array_column($rows, 'id'))->toBe([$blank->id]);
});
