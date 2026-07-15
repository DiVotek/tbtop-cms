<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\MenuForm;
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

function makeMenuAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);

    return $user;
}

it('persists a nested item tree, keeping only fields for each chosen type', function (): void {
    $menu = MenuForm::save([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            [
                'label' => 'Products',
                'type' => 'page',
                'page_id' => '7',
                'target_blank' => true,
                // url/category/anchor for other types must be dropped on a page item
                'url' => 'https://leak.example',
                'category_id' => '99',
                'children' => [
                    ['label' => 'Blog', 'type' => 'category', 'category_id' => '3'],
                    ['label' => 'Laptops', 'type' => 'url', 'url' => 'https://shop/laptops'],
                    ['label' => 'Jump', 'type' => 'anchor', 'anchor' => '#deals'],
                ],
            ],
        ],
    ]);

    expect($menu->exists)->toBeTrue()
        ->and($menu->name)->toBe('Main')
        ->and($menu->key)->toBe('main')
        ->and($menu->items)->toBe([
            [
                'type' => 'page',
                'label' => 'Products',
                'page_id' => 7,
                'target_blank' => true,
                'children' => [
                    ['type' => 'category', 'label' => 'Blog', 'category_id' => 3],
                    ['type' => 'url', 'label' => 'Laptops', 'url' => 'https://shop/laptops'],
                    ['type' => 'anchor', 'label' => 'Jump', 'anchor' => '#deals'],
                ],
            ],
        ]);
});

it('omits target_blank when the checkbox is off', function (): void {
    $menu = MenuForm::save([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            ['label' => 'Home', 'type' => 'url', 'url' => '/', 'target_blank' => false],
        ],
    ]);

    expect($menu->items[0])->not->toHaveKey('target_blank');
});

it('drops rows with an empty label and caps nesting at two levels', function (): void {
    $menu = MenuForm::save([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            ['label' => '', 'type' => 'url', 'url' => 'https://ignored'],
            [
                'label' => 'Kept',
                'type' => 'url',
                'url' => 'https://kept',
                'children' => [
                    // a third level must never persist
                    ['label' => 'Deep', 'type' => 'url', 'url' => 'https://deep', 'children' => [
                        ['label' => 'Too deep', 'type' => 'url', 'url' => 'https://x'],
                    ]],
                ],
            ],
        ],
    ]);

    expect($menu->items)->toHaveCount(1)
        ->and($menu->items[0]['label'])->toBe('Kept')
        ->and($menu->items[0]['children'][0])->not->toHaveKey('children');
});

it('loads a stored menu back into repeater rows', function (): void {
    $menu = Menu::create([
        'name' => 'Footer',
        'key' => 'footer',
        'items' => [['type' => 'url', 'label' => 'Home', 'url' => '/']],
    ]);

    $row = MenuForm::loadRow($menu->id);

    expect($row['name'])->toBe('Footer')
        ->and($row['key'])->toBe('footer')
        ->and($row['items'][0]['label'])->toBe('Home')
        ->and($row['items'][0]['url'])->toBe('/');
});

it('offers only published pages in the page picker, excluding categories and articles', function (): void {
    Page::factory()->create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Live page']]);
    Page::factory()->create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Draft page']]);
    $cat = Page::factory()->create(['type' => 'category', 'status' => PageStatus::Published, 'name' => ['en' => 'A category']]);
    Page::factory()->create(['type' => 'article', 'status' => PageStatus::Published, 'parent_id' => $cat->id, 'name' => ['en' => 'An article']]);

    $labels = array_column(MenuForm::pageOptions(), 'label');

    expect($labels)->toContain('Live page')
        ->and($labels)->not->toContain('Draft page')
        ->and($labels)->not->toContain('A category')
        ->and($labels)->not->toContain('An article');
});

it('offers only published categories in the category picker, excluding pages and articles', function (): void {
    $cat = Page::factory()->create(['type' => 'category', 'status' => PageStatus::Published, 'name' => ['en' => 'Live category']]);
    Page::factory()->create(['type' => 'category', 'status' => PageStatus::Draft, 'name' => ['en' => 'Draft category']]);
    Page::factory()->create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'A page']]);
    Page::factory()->create(['type' => 'article', 'status' => PageStatus::Published, 'parent_id' => $cat->id, 'name' => ['en' => 'An article']]);

    $labels = array_column(MenuForm::categoryOptions(), 'label');

    expect($labels)->toContain('Live category')
        ->and($labels)->not->toContain('Draft category')
        ->and($labels)->not->toContain('A page')
        ->and($labels)->not->toContain('An article');
});

it('shows the menus list to an admin user', function (): void {
    $this->actingAs(makeMenuAdmin(), 'web')
        ->get('/admin/menus', ['X-Inertia' => 'true'])
        ->assertOk();
});

it('shows the menu create page to an admin user', function (): void {
    $this->actingAs(makeMenuAdmin(), 'web')
        ->get('/admin/menus/create', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'admin/page');
});

it('prefills the edit page with the menu record', function (): void {
    $menu = Menu::create([
        'name' => 'Main',
        'key' => 'main',
        'items' => [['type' => 'url', 'label' => 'Home', 'url' => '/']],
    ]);

    $this->actingAs(makeMenuAdmin(), 'web')
        ->get("/admin/menus/{$menu->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.data.menuEditor.name', 'Main');
});

it('redirects a guest away from the menus admin', function (): void {
    $this->get('/admin/menus')->assertRedirect(route('login'));
});
