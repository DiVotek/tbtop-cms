<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
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

it('wires two tabs (General/SEO) with their fields', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $structure = pageEditStructure($page->id)['props']['structure'];

    $form = findNodeByKind($structure, 'form');
    expect($form)->not->toBeNull();

    $tabsNode = findNodeByKind($form, 'tabs');
    expect($tabsNode)->not->toBeNull();

    $tabs = $tabsNode['options']['tabs'];
    expect($tabs)->toHaveCount(2)
        ->and($tabs[0]['label'])->toBe('General')
        ->and($tabs[1]['label'])->toBe('SEO');

    $generalFieldNames = array_column($tabs[0]['body']['options']['children'], 'name');
    expect($generalFieldNames)->toContain('name', 'title', 'heading', 'summary', 'type', 'parent_id', 'status', 'sort_order');

    $seoFieldNames = array_column($tabs[1]['body']['options']['children'], 'name');
    expect($seoFieldNames)->toContain('seo_title', 'seo_description', 'is_indexable');
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

it('hides delete for system pages and keeps it for regular pages', function (): void {
    $system = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Home'], 'is_system' => true]);
    $regular = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $admin = makePageEditAdmin();

    $systemActions = array_column(
        test()->actingAs($admin, 'web')->get("/admin/pages/{$system->id}/edit", ['X-Inertia' => 'true'])
            ->assertOk()->json('props.headerActions') ?? [],
        'name',
    );
    $regularActions = array_column(
        test()->actingAs($admin, 'web')->get("/admin/pages/{$regular->id}/edit", ['X-Inertia' => 'true'])
            ->assertOk()->json('props.headerActions') ?? [],
        'name',
    );

    expect($systemActions)->not->toContain('pageEditDelete')
        ->and($regularActions)->toContain('pageEditDelete');
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

it('quick-save does not clear heading, summary, or content', function (): void {
    $page = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'heading' => ['en' => 'Keep me'],
        'summary' => ['en' => 'Keep this too'],
        'content' => [['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Hi']]],
    ]);

    PageForm::saveQuick([
        'name' => ['en' => 'About Renamed'],
        'status' => PageStatus::Draft->value,
        'parent_id' => 'none',
        'sort_order' => 3,
    ], $page->id);

    $page->refresh();

    expect($page->getTranslation('name', 'en'))->toBe('About Renamed')
        ->and($page->status)->toBe(PageStatus::Draft)
        ->and($page->sort_order)->toBe(3)
        ->and($page->getTranslation('heading', 'en'))->toBe('Keep me')
        ->and($page->getTranslation('summary', 'en'))->toBe('Keep this too')
        ->and($page->content)->toHaveCount(1);
});

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
