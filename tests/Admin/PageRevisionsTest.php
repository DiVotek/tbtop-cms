<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeRevisionsAdmin(): AdminUser
{
    static $n = 0;
    $n++;
    $user = AdminUser::create(['name' => 'Admin ' . $n, 'email' => "revisions-admin{$n}@example.com", 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

/** @return array<string, mixed> Minimal valid general/SEO payload for the pageEditor form. */
function revisionsBaseEditPayload(): array
{
    return [
        'name' => ['en' => 'About Us'],
        'title' => ['en' => ''],
        'slug' => ['en' => 'about-us'],
        'heading' => ['en' => ''],
        'summary' => ['en' => ''],
        'type' => 'page',
        'parent_id' => 'none',
        'status' => PageStatus::Published->value,
        'sort_order' => 0,
        'seo_title' => ['en' => ''],
        'seo_description' => ['en' => ''],
        'seo_focus_keyword' => ['en' => ''],
        'og_title' => ['en' => ''],
        'og_description' => ['en' => ''],
        'og_image' => '',
        'is_indexable' => true,
    ];
}

it('creates a kind=create revision when a page is created', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);

    $revisions = PageRevision::query()->where('page_id', $page->id)->get();

    expect($revisions)->toHaveCount(1)
        ->and($revisions->first()->kind)->toBe('create');
});

it('creates a kind=save revision with the changed name and acting admin id when the edit form is saved', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $admin = makeRevisionsAdmin();

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...revisionsBaseEditPayload(), 'name' => ['en' => 'About Updated']],
    )->assertRedirect();

    $saveRevision = PageRevision::query()->where('page_id', $page->id)->where('kind', 'save')->first();

    expect($saveRevision)->not->toBeNull()
        ->and($saveRevision->payload['name'])->toBe(['en' => 'About Updated'])
        ->and($saveRevision->user_id)->toBe($admin->id);
});

it('does not create a revision for a save that only changes sort_order/status', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'About']]);
    $admin = makeRevisionsAdmin();

    // Baseline save: Page::create() above only set type/status/name, so the
    // untouched columns (title/heading/summary/body/seo/content) are still
    // null — the very first form resubmission would flip them to the form's
    // empty-string/empty-array representation and *would* register as an
    // editorial change. Establish the form's shape as the current baseline
    // first, so the assertion below tests only what it claims to.
    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...revisionsBaseEditPayload(), 'name' => ['en' => 'About']],
    )->assertRedirect();

    $revisionCountAfterBaseline = PageRevision::query()->where('page_id', $page->id)->count();

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...revisionsBaseEditPayload(),
            'name' => ['en' => 'About'], // unchanged
            'status' => PageStatus::Published->value, // changed
            'sort_order' => 7, // changed
        ],
    )->assertRedirect();

    $page->refresh();
    expect($page->status)->toBe(PageStatus::Published)
        ->and($page->sort_order)->toBe(7)
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe($revisionCountAfterBaseline);
});

it('prunes the oldest revision once the retention cap is exceeded', function (): void {
    config(['tbtop-cms.revisions.keep' => 3]);
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'v0']]);
    $admin = makeRevisionsAdmin();

    foreach (['v1', 'v2', 'v3'] as $name) {
        test()->actingAs($admin, 'web')->post(
            "/admin/pages/{$page->id}/edit/forms/pageEditor",
            [...revisionsBaseEditPayload(), 'name' => ['en' => $name]],
        )->assertRedirect();
    }

    // create(v0) + 3 saves(v1,v2,v3) = 4 snapshots written, capped to 3 kept.
    $revisions = PageRevision::query()->where('page_id', $page->id)->orderBy('id')->get();

    expect($revisions)->toHaveCount(3);

    $names = $revisions->map(fn (PageRevision $r): string => $r->payload['name']['en'])->all();
    expect($names)->toBe(['v1', 'v2', 'v3'])
        ->and($names)->not->toContain('v0');
});

it('writes no revisions at all when revisions.keep is 0', function (): void {
    config(['tbtop-cms.revisions.keep' => 0]);
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $admin = makeRevisionsAdmin();

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...revisionsBaseEditPayload(), 'name' => ['en' => 'About Updated']],
    )->assertRedirect();

    expect(PageRevision::query()->where('page_id', $page->id)->count())->toBe(0);
});

it('hides the revisions action entirely when revisions.keep is 0, even over leftover rows', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $admin = makeRevisionsAdmin();

    // A row written while the feature was on must not resurface as an
    // empty-shell action after the host disables the feature.
    expect(PageRevision::query()->where('page_id', $page->id)->count())->toBe(1);
    config(['tbtop-cms.revisions.keep' => 0]);

    $json = test()->actingAs($admin, 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json();

    $structure = json_encode($json['props']['structure']);

    expect($structure)->not->toContain(__('tbtop-cms::pages.revisions.title'))
        ->and($structure)->not->toContain('pageRevisionRestore_');
});

it('renders the revisions action with the restore action for an older revision when revisions are present', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'v0']]);
    $admin = makeRevisionsAdmin();

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...revisionsBaseEditPayload(), 'name' => ['en' => 'v1']],
    )->assertRedirect();

    $oldest = PageRevision::query()->where('page_id', $page->id)->orderBy('id')->firstOrFail();

    $json = test()->actingAs($admin, 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json();

    $structure = json_encode($json['props']['structure']);

    expect($structure)->toContain(__('tbtop-cms::pages.revisions.title'))
        ->and($structure)->toContain("pageRevisionRestore_{$oldest->id}");
});

it('restores a page from a revision via the action endpoint, leaves status untouched, and writes a kind=restore revision', function (): void {
    $page = Page::create([
        'type' => 'page',
        'status' => PageStatus::Draft,
        'name' => ['en' => 'Original name'],
        'is_indexable' => true,
    ]);
    $admin = makeRevisionsAdmin();

    // Snapshot to restore back to.
    $originalRevision = PageRevision::query()->where('page_id', $page->id)->where('kind', 'create')->firstOrFail();

    // Change name and status after the snapshot was taken.
    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...revisionsBaseEditPayload(),
            'name' => ['en' => 'Changed name'],
            'status' => PageStatus::Published->value,
        ],
    )->assertRedirect();

    $page->refresh();
    expect($page->getTranslation('name', 'en'))->toBe('Changed name')
        ->and($page->status)->toBe(PageStatus::Published);

    // The revision payload captured status=draft (display-only) — restoring
    // it must revert the name but must NOT touch the page's current status.
    expect($originalRevision->payload['status'])->toBe('draft');

    $response = test()->actingAs($admin, 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageRevisionRestore_{$originalRevision->id}",
        ['payload' => []],
    );

    $response->assertOk();
    $page->refresh();

    expect($page->getTranslation('name', 'en'))->toBe('Original name')
        ->and($page->status)->toBe(PageStatus::Published); // unchanged despite payload saying draft

    $restoreRevision = PageRevision::query()->where('page_id', $page->id)->where('kind', 'restore')->first();
    expect($restoreRevision)->not->toBeNull();

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect');
});

it('does not offer a restore action for the newest revision', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $admin = makeRevisionsAdmin();

    $newest = PageRevision::query()->where('page_id', $page->id)->where('kind', 'create')->firstOrFail();

    $json = test()->actingAs($admin, 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json();

    $structure = json_encode($json['props']['structure']);

    expect($structure)->not->toContain("pageRevisionRestore_{$newest->id}");
});

// Testbench's shared sqlite `testing` connection ships with
// foreign_key_constraints disabled (see CmsUserProfileForeignKeyTest for the
// same finding on cms_user_profiles): the FK exists in the schema but isn't
// enforced, so PageObserver::deleting()'s slug cleanup + Eloquent cascading
// wouldn't prove anything here. Instead, build a throwaway `fk_test` sqlite
// connection with FK enforcement on, replicating exactly what
// create_page_revisions_table.php produces, to prove the DB-level cascade
// actually fires on a hard delete.
it('cascades its revisions when a page is force-deleted (DB-level FK)', function (): void {
    config(['database.connections.fk_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]]);

    Schema::connection('fk_test')->create('tbtop_pages', function (Blueprint $table): void {
        $table->id();
        $table->string('type');
    });

    Schema::connection('fk_test')->create('tbtop_page_revisions', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('page_id')->constrained('tbtop_pages')->cascadeOnDelete();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('kind');
        $table->json('payload');
        $table->timestamp('created_at')->nullable();
    });

    $pageId = DB::connection('fk_test')->table('tbtop_pages')->insertGetId(['type' => 'page']);
    DB::connection('fk_test')->table('tbtop_page_revisions')->insert([
        'page_id' => $pageId,
        'user_id' => null,
        'kind' => 'create',
        'payload' => json_encode(['name' => []]),
        'created_at' => now(),
    ]);

    DB::connection('fk_test')->table('tbtop_pages')->where('id', $pageId)->delete();

    expect(DB::connection('fk_test')->table('tbtop_page_revisions')->where('page_id', $pageId)->count())->toBe(0);

    DB::purge('fk_test');
});
