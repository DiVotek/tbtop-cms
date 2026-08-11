<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Template\Models\Template;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeAuthorTrackingAdmin(string $suffix): AdminUser
{
    $user = AdminUser::create(['name' => "Admin {$suffix}", 'email' => "author-tracking-{$suffix}@example.com", 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

/** @return array<string, mixed> Minimal valid general/SEO payload for the pageEditor form. */
function authorTrackingBaseEditPayload(): array
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

it('stamps created_by and updated_by with the acting admin when a page is created via the create modal', function (): void {
    $admin = makeAuthorTrackingAdmin('creator');

    test()->actingAs($admin, 'web')->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => ['form' => [
            'name' => ['en' => 'New Page'],
            'title' => ['en' => ''],
            'slug' => ['en' => ''],
            'type' => 'page',
            'parent_id' => 'none',
        ]],
    ])->assertOk();

    $page = Page::query()->where('type', 'page')->firstOrFail();

    expect($page->created_by)->toBe($admin->id)
        ->and($page->updated_by)->toBe($admin->id);
});

it('stamps updated_by with a second admin on edit, leaving created_by unchanged', function (): void {
    $creator = makeAuthorTrackingAdmin('original');
    $editor = makeAuthorTrackingAdmin('second');

    test()->actingAs($creator, 'web')->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => ['form' => [
            'name' => ['en' => 'About Us'],
            'title' => ['en' => ''],
            'slug' => ['en' => 'about-us'],
            'type' => 'page',
            'parent_id' => 'none',
        ]],
    ])->assertOk();

    $page = Page::query()->where('type', 'page')->firstOrFail();
    expect($page->created_by)->toBe($creator->id);

    test()->actingAs($editor, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...authorTrackingBaseEditPayload(), 'lock_version' => $page->lock_version, 'name' => ['en' => 'About Us Updated']],
    )->assertRedirect();

    $page->refresh();
    expect($page->created_by)->toBe($creator->id)
        ->and($page->updated_by)->toBe($editor->id);
});

it('leaves both author columns null for a programmatic Page::create() with no authenticated user', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'System page']]);

    expect($page->created_by)->toBeNull()
        ->and($page->updated_by)->toBeNull();
});

it('stamps created_by/updated_by on template create and edit via their admin form endpoints', function (): void {
    $creator = makeAuthorTrackingAdmin('template-creator');
    $editor = makeAuthorTrackingAdmin('template-editor');

    test()->actingAs($creator, 'web')->post('/admin/templates/create/forms/templateEditor', [
        'name' => 'Landing',
        'content' => [],
    ])->assertRedirect();

    $template = Template::query()->where('name', 'Landing')->firstOrFail();
    expect($template->created_by)->toBe($creator->id)
        ->and($template->updated_by)->toBe($creator->id);

    test()->actingAs($editor, 'web')->post("/admin/templates/{$template->id}/edit/forms/templateEditor", [
        'name' => 'Landing Updated',
        'content' => [],
    ])->assertRedirect();

    $template->refresh();
    expect($template->created_by)->toBe($creator->id)
        ->and($template->updated_by)->toBe($editor->id)
        ->and($template->name)->toBe('Landing Updated');
});

it('stamps created_by/updated_by on a redirect created via its create-modal action', function (): void {
    $admin = makeAuthorTrackingAdmin('redirect-creator');

    test()->actingAs($admin, 'web')->postJson('/admin/redirects/actions/redirectCreateSave', [
        'payload' => ['form' => [
            'from_path' => 'old-path',
            'to_path' => 'new-path',
            'locale' => 'all',
            'status_code' => '301',
        ]],
    ])->assertOk();

    $redirect = Redirect::query()->where('from_path', 'old-path')->firstOrFail();

    expect($redirect->created_by)->toBe($admin->id)
        ->and($redirect->updated_by)->toBe($admin->id);
});

it('attributes an auto-created redirect from a slug rename to the acting admin', function (): void {
    $admin = makeAuthorTrackingAdmin('slug-renamer');
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);
    $page->refresh();

    // Renaming the slug (not the name) is what PageSlugRedirects reacts to —
    // the page-edit form submit runs inside this admin's authenticated
    // request, so the resulting auto-redirect's created_by should attribute
    // to them even though they never touched a redirect form directly.
    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...authorTrackingBaseEditPayload(), 'lock_version' => $page->lock_version, 'slug' => ['en' => 'about-us-renamed']],
    )->assertRedirect();

    $redirect = Redirect::query()->where('is_auto', true)->first();

    expect($redirect)->not->toBeNull()
        ->and($redirect->created_by)->toBe($admin->id);
});

it('shows the d.m.Y H:i formatted updated timestamp and author name in the page-edit status bar props', function (): void {
    $admin = makeAuthorTrackingAdmin('status-bar');
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);
    $page->refresh();

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...authorTrackingBaseEditPayload(), 'lock_version' => $page->lock_version, 'name' => ['en' => 'About Us Updated']],
    )->assertRedirect();

    $page->refresh();

    $structure = test()->actingAs($admin, 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $json = json_encode($structure);

    expect($json)->toContain($page->updated_at->format('d.m.Y H:i'))
        ->and($json)->toContain('Admin status-bar');
});

it('includes an updated-by column, hidden by default, in the pages list table structure', function (): void {
    $admin = makeAuthorTrackingAdmin('pages-table');
    Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);

    $structure = test()->actingAs($admin, 'web')
        ->get('/admin/pages', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $table = null;
    $walk = function (array $node) use (&$walk, &$table): void {
        if (($node['kind'] ?? null) === 'table') {
            $table ??= $node;

            return;
        }
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            $walk($child);
        }
    };
    $walk($structure);

    expect($table)->not->toBeNull();

    $columns = $table['options']['columns'] ?? [];
    $byName = array_column($columns, null, 'name');

    expect($byName)->toHaveKey('updated_by_name')
        ->and($byName['updated_by_name']['hiddenByDefault'] ?? null)->toBeTrue()
        ->and($byName['updated_by_name']['label'] ?? null)->toBe(__('tbtop-cms::pages.columns.updated_by'));
});
