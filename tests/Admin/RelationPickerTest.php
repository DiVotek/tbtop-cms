<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Admin\Pages\Support\PageFormOptions;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Menu\Models\Menu;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeRelationPickerAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);

    return $user;
}

// --- parent_id relation-search: create context (PagesPage) ---

it('parent_id relation-search on the create page returns only categories', function (): void {
    $category = Page::factory()->category()->create(['name' => ['en' => 'Blog']]);
    Page::factory()->create(['type' => 'page', 'name' => ['en' => 'About']]);

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        '/admin/pages/relation-search/parent_id',
        ['search' => ''],
    );

    $response->assertOk();
    $options = $response->json('options');
    expect($options)->toHaveCount(1)
        ->and($options[0])->toMatchArray(['value' => (string) $category->id, 'label' => 'Blog']);
});

it('parent_id relation-search narrows results by name', function (): void {
    Page::factory()->category()->create(['name' => ['en' => 'Blog']]);
    Page::factory()->category()->create(['name' => ['en' => 'Shop']]);

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        '/admin/pages/relation-search/parent_id',
        ['search' => 'blo'],
    );

    $response->assertOk();
    $options = $response->json('options');
    expect($options)->toHaveCount(1)
        ->and($options[0]['label'])->toBe('Blog');
});

it('parent_id relation-search caps results at 50', function (): void {
    Page::factory()->category()->count(55)->create();

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        '/admin/pages/relation-search/parent_id',
        ['search' => ''],
    );

    $response->assertOk();
    expect($response->json('options'))->toHaveCount(50);
});

it('parent_id relation-search resolve mode returns the picked category', function (): void {
    $category = Page::factory()->category()->create(['name' => ['en' => 'Blog']]);

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        '/admin/pages/relation-search/parent_id',
        ['value' => (string) $category->id],
    );

    $response->assertOk()
        ->assertExactJson(['option' => ['value' => (string) $category->id, 'label' => 'Blog']]);
});

// --- parent_id relation-search: edit context (PageEditPage) excludes self + descendants ---

it('parent_id relation-search on the edit page excludes the page itself and its descendants', function (): void {
    $grandparent = Page::factory()->category()->create(['name' => ['en' => 'A']]);
    $parent = Page::factory()->category()->create(['name' => ['en' => 'B'], 'parent_id' => $grandparent->id]);
    $child = Page::factory()->category()->create(['name' => ['en' => 'C'], 'parent_id' => $parent->id]);
    $unrelated = Page::factory()->category()->create(['name' => ['en' => 'D']]);

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        "/admin/pages/{$parent->id}/edit/relation-search/parent_id",
        ['search' => ''],
    );

    $response->assertOk();
    $ids = array_column($response->json('options'), 'value');

    expect($ids)->toContain((string) $grandparent->id)
        ->and($ids)->toContain((string) $unrelated->id)
        ->and($ids)->not->toContain((string) $parent->id)
        ->and($ids)->not->toContain((string) $child->id);
});

// --- template picker (TemplatesPage applyAction target_page_id) ---

it('template target_page_id relation-search returns pages of any status', function (): void {
    Page::factory()->create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Live']]);
    Page::factory()->create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Draft']]);

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        '/admin/templates/relation-search/target_page_id',
        ['search' => ''],
    );

    $response->assertOk();
    $labels = array_column($response->json('options'), 'label');

    expect($labels)->toContain('Live')
        ->and($labels)->toContain('Draft');
});

it('template target_page_id relation-search resolve mode returns the picked page', function (): void {
    $page = Page::factory()->create(['type' => 'page', 'name' => ['en' => 'Contact']]);

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        '/admin/templates/relation-search/target_page_id',
        ['value' => (string) $page->id],
    );

    $response->assertOk()
        ->assertExactJson(['option' => ['value' => (string) $page->id, 'label' => 'Contact']]);
});

// --- MenuForm page_id/category_id relation-search (repeater subfields) ---

it('menu page_id relation-search on the create page returns only published pages', function (): void {
    Page::factory()->create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Live page']]);
    Page::factory()->create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Draft page']]);
    Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'A category']]);

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        '/admin/menus/create/relation-search/page_id',
        ['search' => ''],
    );

    $response->assertOk();
    $labels = array_column($response->json('options'), 'label');

    expect($labels)->toContain('Live page')
        ->and($labels)->not->toContain('Draft page')
        ->and($labels)->not->toContain('A category');
});

it('menu category_id relation-search resolve mode returns the picked category', function (): void {
    $category = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Blog']]);

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        '/admin/menus/create/relation-search/category_id',
        ['value' => (string) $category->id],
    );

    $response->assertOk()
        ->assertExactJson(['option' => ['value' => (string) $category->id, 'label' => 'Blog']]);
});

it('menu page_id relation-search resolves on the edit page route too', function (): void {
    $menu = Menu::create(['name' => 'Main', 'key' => 'main', 'items' => []]);
    $page = Page::factory()->create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Live page']]);

    $response = test()->actingAs(makeRelationPickerAdmin(), 'web')->postJson(
        "/admin/menus/{$menu->id}/edit/relation-search/page_id",
        ['value' => (string) $page->id],
    );

    $response->assertOk()
        ->assertExactJson(['option' => ['value' => (string) $page->id, 'label' => 'Live page']]);
});

// --- PageForm::save() parent_id regression coverage for the new field's clear semantics ---

it('persists a parent set via the relation picker raw id', function (): void {
    $category = Page::factory()->category()->create(['name' => ['en' => 'Blog']]);

    $page = PageForm::save([
        'type' => 'article',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'Post'],
        'parent_id' => (string) $category->id,
    ]);

    expect($page->parent_id)->toBe($category->id);
});

it('clears the parent to null when the relation picker submits an empty value', function (): void {
    // 'category' under 'category', not 'article' — an article requires a
    // non-null parent (PageHierarchy invariant), which would mask the
    // clear-to-null assertion behind an unrelated DomainException, and a
    // plain 'page' cannot be a category's child at all.
    $parentCategory = Page::factory()->category()->create(['name' => ['en' => 'Blog']]);
    $page = Page::factory()->category()->create(['name' => ['en' => 'Sub'], 'parent_id' => $parentCategory->id]);

    PageForm::save([
        'type' => 'category',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'Sub'],
        'parent_id' => '',
    ], $page->id);

    expect($page->fresh()->parent_id)->toBeNull();
});

it('still maps the legacy NO_PARENT sentinel to null (regression)', function (): void {
    $parentCategory = Page::factory()->category()->create(['name' => ['en' => 'Blog']]);
    $page = Page::factory()->category()->create(['name' => ['en' => 'Sub'], 'parent_id' => $parentCategory->id]);

    PageForm::save([
        'type' => 'category',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'Sub'],
        'parent_id' => PageFormOptions::NO_PARENT,
    ], $page->id);

    expect($page->fresh()->parent_id)->toBeNull();
});

it('loads the raw parent id (not the legacy sentinel) for the edit picker to resolve', function (): void {
    $category = Page::factory()->category()->create(['name' => ['en' => 'Blog']]);
    $page = Page::factory()->create(['type' => 'article', 'name' => ['en' => 'Post'], 'parent_id' => $category->id]);

    expect(PageForm::loadRow($page->id)['parent_id'])->toBe((string) $category->id);
});

it('loads a null parent_id (not the legacy sentinel) when the page has no parent', function (): void {
    $page = Page::factory()->create(['type' => 'page', 'name' => ['en' => 'Root'], 'parent_id' => null]);

    expect(PageForm::loadRow($page->id)['parent_id'])->toBeNull();
});

it('still rejects placing a page under its own descendant via the save-time guard', function (): void {
    $parent = Page::factory()->category()->create(['name' => ['en' => 'Parent']]);
    $child = Page::factory()->category()->create(['name' => ['en' => 'Child'], 'parent_id' => $parent->id]);

    PageForm::save([
        'type' => 'category',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'Parent'],
        'parent_id' => (string) $child->id,
    ], $parent->id);
})->throws(ValidationException::class);
