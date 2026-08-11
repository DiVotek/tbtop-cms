<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeLockingAdmin(): AdminUser
{
    static $n = 0;
    $n++;
    $user = AdminUser::create(['name' => 'Admin ' . $n, 'email' => "locking-admin{$n}@example.com", 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

/** @return array<string, mixed> Minimal valid general/SEO payload for the pageEditor form. */
function lockingBaseEditPayload(): array
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
        'seo_title' => '',
        'seo_description' => '',
        'seo_focus_keyword' => '',
        'og_title' => '',
        'og_description' => '',
        'og_image' => '',
        'is_indexable' => true,
    ];
}

/** Reads the tbtop.effects Inertia flash left by the most recent form-submit response (FormSubmitController's back()). */
function lockingFlashedEffects(): array
{
    return session('inertia.flash_data')['tbtop.effects'] ?? [];
}

it('includes lock_version in the pageEditor record loaded for the edit form', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $admin = makeLockingAdmin();

    $json = test()->actingAs($admin, 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json();

    expect($json['props']['data']['pageEditor']['lock_version'])->toBe(0);
});

it('saves with a matching lock_version and increments it in the database', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $admin = makeLockingAdmin();

    // Page::create()'s in-memory model doesn't know the DB-applied default
    // (lock_version has no assignment in the create() call, so the attribute
    // reads null until reloaded) — refresh() to see the schema default (0).
    $page->refresh();
    expect($page->lock_version)->toBe(0);

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...lockingBaseEditPayload(), 'lock_version' => 0, 'name' => ['en' => 'About Updated']],
    )->assertRedirect();

    $page->refresh();
    expect($page->getTranslation('name', 'en'))->toBe('About Updated')
        ->and($page->lock_version)->toBe(1);
});

it('rejects a save with a stale lock_version, leaving the database and revisions untouched', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Original']]);
    $admin = makeLockingAdmin();

    // Bump the row's version once so the form's lock_version (still 0) is stale.
    $page->update(['name' => ['en' => 'Bumped']]);
    $page->refresh();
    expect($page->lock_version)->toBe(1);

    $revisionCountBefore = PageRevision::query()->where('page_id', $page->id)->count();

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...lockingBaseEditPayload(), 'lock_version' => 0, 'name' => ['en' => 'Stale edit']],
    )->assertRedirect(); // FormSubmitController's back() is always an HTTP redirect

    $page->refresh();
    expect($page->getTranslation('name', 'en'))->toBe('Bumped')
        ->and($page->lock_version)->toBe(1)
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe($revisionCountBefore);

    $effects = lockingFlashedEffects();
    $notify = collect($effects)->firstWhere('kind', 'notify');
    expect($notify)->not->toBeNull()
        ->and($notify['level'])->toBe('error')
        ->and($notify['message'])->toBe(__('tbtop-cms::pages.conflict.message'))
        // Notify only — back() already reloads the winning record (fresh
        // lock_version); a redirect effect would revisit and drop the toast.
        ->and(collect($effects)->pluck('kind'))->not->toContain('redirect');
});

it('conflicts when editor B saves with the version editor A already superseded', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'v0']]);
    $editorA = makeLockingAdmin();
    $editorB = makeLockingAdmin();

    // Both A and B "load" the page at lock_version 0 (refresh() needed here
    // for the same reason as the previous test: the freshly created in-memory
    // model doesn't reflect the DB default until reloaded).
    $page->refresh();
    expect($page->lock_version)->toBe(0);

    // A saves first — the row moves to lock_version 1.
    test()->actingAs($editorA, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...lockingBaseEditPayload(), 'lock_version' => 0, 'name' => ['en' => 'A wins']],
    )->assertRedirect();

    $page->refresh();
    expect($page->lock_version)->toBe(1)
        ->and($page->getTranslation('name', 'en'))->toBe('A wins');

    // B still holds the stale lock_version=0 form and submits it.
    test()->actingAs($editorB, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...lockingBaseEditPayload(), 'lock_version' => 0, 'name' => ['en' => 'B tries to overwrite']],
    )->assertRedirect();

    $page->refresh();
    expect($page->getTranslation('name', 'en'))->toBe('A wins')
        ->and($page->lock_version)->toBe(1);

    $notify = collect(lockingFlashedEffects())->firstWhere('kind', 'notify');
    expect($notify)->not->toBeNull()->and($notify['level'])->toBe('error');
});

it('creates a page via the pages create-modal action, which carries no lock_version key', function (): void {
    $admin = makeLockingAdmin();

    $response = test()->actingAs($admin, 'web')->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => [
            'form' => [
                'name' => ['en' => 'New Page'],
                'title' => ['en' => ''],
                'slug' => ['en' => ''],
                'type' => 'page',
                'parent_id' => 'none',
            ],
        ],
    ]);

    $response->assertOk();
    $page = Page::query()->where('type', 'page')->firstOrFail();
    expect($page->getTranslation('name', 'en'))->toBe('New Page')
        ->and($page->lock_version)->toBe(0);
});

it('skips the lock_version check on an edit form payload that omits the key entirely (legacy/back-compat)', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Original']]);
    $admin = makeLockingAdmin();

    // Bump the row so a version-carrying submit of 0 WOULD conflict...
    $page->update(['name' => ['en' => 'Bumped']]);
    $page->refresh();
    expect($page->lock_version)->toBe(1);

    // ...but this payload has no lock_version key at all.
    $payload = lockingBaseEditPayload();
    unset($payload['lock_version']);

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...$payload, 'name' => ['en' => 'Saved without version check']],
    )->assertRedirect();

    $page->refresh();
    expect($page->getTranslation('name', 'en'))->toBe('Saved without version check');

    $notify = collect(lockingFlashedEffects())->firstWhere('kind', 'notify');
    expect($notify['level'] ?? null)->not->toBe('error');
});

it('bumps lock_version on revision restore, so a form save carrying the pre-restore version then conflicts', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Original name']]);
    $admin = makeLockingAdmin();

    $originalRevision = PageRevision::query()->where('page_id', $page->id)->where('kind', 'create')->firstOrFail();

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...lockingBaseEditPayload(), 'lock_version' => 0, 'name' => ['en' => 'Changed name']],
    )->assertRedirect();

    $page->refresh();
    expect($page->lock_version)->toBe(1);

    // Editor's form is still open at lock_version=1 (what it just saved).
    $staleFormVersion = $page->lock_version;

    $restoreResponse = test()->actingAs($admin, 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageRevisionRestore_{$originalRevision->id}",
        ['payload' => []],
    );
    $restoreResponse->assertOk();

    $page->refresh();
    expect($page->getTranslation('name', 'en'))->toBe('Original name')
        ->and($page->lock_version)->toBe(2); // restore's save() bumped it again

    // Now a save carrying the pre-restore version (1) must conflict.
    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...lockingBaseEditPayload(), 'lock_version' => $staleFormVersion, 'name' => ['en' => 'Should not apply']],
    )->assertRedirect();

    $page->refresh();
    expect($page->getTranslation('name', 'en'))->toBe('Original name')
        ->and($page->lock_version)->toBe(2);

    $notify = collect(lockingFlashedEffects())->firstWhere('kind', 'notify');
    expect($notify)->not->toBeNull()->and($notify['level'])->toBe('error');
});

it('conflicts a block-add action carrying a stale lock_version, persisting no new block', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About'], 'content' => []]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);
    $admin = makeLockingAdmin();

    // Bump the row so lock_version=0 (what the "open form" below carries) is stale.
    $page->update(['name' => ['en' => 'Bumped']]);
    $page->refresh();
    expect($page->lock_version)->toBe(1);

    $response = test()->actingAs($admin, 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockAdd_Simple",
        ['payload' => ['form' => [...lockingBaseEditPayload(), 'lock_version' => 0, 'name' => ['en' => 'Bumped']]]],
    );

    $response->assertOk();
    $page->refresh();

    expect($page->content)->toBe([])
        ->and($page->lock_version)->toBe(1);

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->not->toContain('redirect');

    $notify = collect($response->json('effects'))->firstWhere('kind', 'notify');
    expect($notify)->not->toBeNull()
        ->and($notify['level'])->toBe('error')
        ->and($notify['message'])->toBe(__('tbtop-cms::pages.conflict.message'));
});
