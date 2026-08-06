<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Admin\Dsl\RuleWalker;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Enums\CmsRole;
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
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);

    return $user;
}

it('persists a nested item tree, keeping only fields for each chosen type', function (): void {
    $menu = MenuForm::save([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            [
                'label' => ['en' => 'Products'],
                'type' => 'page',
                'page_id' => '7',
                'target_blank' => true,
                // url/category/anchor for other types must be dropped on a page item
                'url' => ['en' => 'https://leak.example'],
                'category_id' => '99',
                'children' => [
                    ['label' => ['en' => 'Blog'], 'type' => 'category', 'category_id' => '3'],
                    ['label' => ['en' => 'Laptops'], 'type' => 'url', 'url' => ['en' => 'https://shop/laptops']],
                    ['label' => ['en' => 'Jump'], 'type' => 'anchor', 'anchor' => ['en' => '#deals']],
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
                'label' => ['en' => 'Products'],
                'page_id' => 7,
                'target_blank' => true,
                'children' => [
                    ['type' => 'category', 'label' => ['en' => 'Blog'], 'category_id' => 3],
                    ['type' => 'url', 'label' => ['en' => 'Laptops'], 'url' => ['en' => 'https://shop/laptops']],
                    ['type' => 'anchor', 'label' => ['en' => 'Jump'], 'anchor' => ['en' => '#deals']],
                ],
            ],
        ]);
});

it('omits target_blank when the checkbox is off', function (): void {
    $menu = MenuForm::save([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            ['label' => ['en' => 'Home'], 'type' => 'url', 'url' => ['en' => '/'], 'target_blank' => false],
        ],
    ]);

    expect($menu->items[0])->not->toHaveKey('target_blank');
});

it('requires each type target only for its type, scoped per row at both levels', function (): void {
    $rules = RuleWalker::collect(MenuForm::fields(new S, false));

    // The reference must be the full wildcard path to the sibling type, or
    // Laravel resolves it against a non-existent top-level `type` and never fires.
    // Translatable url/anchor/label expand to per-locale keys; only the
    // default locale carries the required_if — other locales fall back.
    expect($rules['items.*.url'])->toBe(['nullable', 'array'])
        ->and($rules['items.*.url.en'])->toBe(['required_if:items.*.type,url'])
        ->and($rules['items.*.label.en'])->toBe(['required_if:items.*.type,url,anchor'])
        ->and($rules['items.*.page_id'])->toBe(['required_if:items.*.type,page'])
        ->and($rules['items.*.children.*.url.en'])->toBe(['required_if:items.*.children.*.type,url']);

    // And the rule actually fires: an empty url on a url item is rejected.
    $data = ['name' => 'X', 'key' => 'x', 'items' => [['type' => 'url', 'label' => ['en' => 'a'], 'url' => ['en' => '']]]];
    expect(validator($data, $rules)->fails())->toBeTrue();

    $valid = ['name' => 'X', 'key' => 'x', 'items' => [['type' => 'url', 'label' => ['en' => 'a'], 'url' => ['en' => '/ok']]]];
    expect(validator($valid, $rules)->fails())->toBeFalse();

    // A label is not required on a page item — it falls back to the page name.
    $pageItem = ['name' => 'X', 'key' => 'x', 'items' => [['type' => 'page', 'page_id' => '1', 'label' => ['en' => '']]]];
    expect(validator($pageItem, $rules)->fails())->toBeFalse();
});

it('drops items whose type has no target, for every type', function (): void {
    $menu = MenuForm::save([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            ['label' => ['en' => 'No page'], 'type' => 'page', 'page_id' => ''],
            ['label' => ['en' => 'No category'], 'type' => 'category', 'category_id' => null],
            ['label' => ['en' => 'Blank url'], 'type' => 'url', 'url' => ['en' => '   ']],
            ['label' => ['en' => 'Empty anchor'], 'type' => 'anchor', 'anchor' => ['en' => '']],
            ['label' => ['en' => 'Real'], 'type' => 'url', 'url' => ['en' => 'https://ok']],
        ],
    ]);

    expect($menu->items)->toBe([
        ['type' => 'url', 'label' => ['en' => 'Real'], 'url' => ['en' => 'https://ok']],
    ]);
});

it('drops rows with an empty label and caps nesting at two levels', function (): void {
    $menu = MenuForm::save([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            ['label' => ['en' => ''], 'type' => 'url', 'url' => ['en' => 'https://ignored']],
            [
                'label' => ['en' => 'Kept'],
                'type' => 'url',
                'url' => ['en' => 'https://kept'],
                'children' => [
                    // a third level must never persist
                    ['label' => ['en' => 'Deep'], 'type' => 'url', 'url' => ['en' => 'https://deep'], 'children' => [
                        ['label' => ['en' => 'Too deep'], 'type' => 'url', 'url' => ['en' => 'https://x']],
                    ]],
                ],
            ],
        ],
    ]);

    expect($menu->items)->toHaveCount(1)
        ->and($menu->items[0]['label'])->toBe(['en' => 'Kept'])
        ->and($menu->items[0]['children'][0])->not->toHaveKey('children');
});

it('keeps the key fixed on edit even if a different key is submitted', function (): void {
    $menu = Menu::create(['name' => 'Header', 'key' => 'header', 'items' => []]);

    // A renamed menu (and a tampered key field) must not move the stored key.
    MenuForm::save(['name' => 'Header renamed', 'key' => 'header-renamed', 'items' => []], $menu->id);

    expect($menu->fresh()->key)->toBe('header')
        ->and($menu->fresh()->name)->toBe('Header renamed');
});

it('loads a stored menu back into repeater rows, lifting legacy scalars into locale maps', function (): void {
    $menu = Menu::create([
        'name' => 'Footer',
        'key' => 'footer',
        // pre-translation row: scalar label/url belong to the default locale
        'items' => [['type' => 'url', 'label' => 'Home', 'url' => '/']],
    ]);

    $row = MenuForm::loadRow($menu->id);

    expect($row['name'])->toBe('Footer')
        ->and($row['key'])->toBe('footer')
        ->and($row['items'][0]['label'])->toBe(['en' => 'Home'])
        ->and($row['items'][0]['url'])->toBe(['en' => '/']);
});

it('stores only label overrides for page items, dropping locales that echo the page name', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About us', 'uk' => 'Про нас'],
    ]);

    $menu = MenuForm::save([
        'name' => 'Main',
        'key' => 'main',
        'items' => [[
            'type' => 'page',
            'page_id' => (string) $page->id,
            // en echoes the page name (untouched prefill), uk is a real override
            'label' => ['en' => 'About us', 'uk' => 'Наша команда'],
        ]],
    ]);

    expect($menu->items[0]['label'])->toBe(['uk' => 'Наша команда']);

    // A fully matching label stores no override at all — the fallback stays live.
    $menu = MenuForm::save([
        'name' => 'Main',
        'key' => 'main',
        'items' => [[
            'type' => 'page',
            'page_id' => (string) $page->id,
            'label' => ['en' => 'About us', 'uk' => 'Про нас'],
        ]],
    ], $menu->id);

    expect($menu->items[0])->not->toHaveKey('label');
});

it('prefills page-item labels from name translations on load, keeping stored overrides', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About us', 'uk' => 'Про нас'],
    ]);

    $menu = Menu::create([
        'name' => 'Main',
        'key' => 'main',
        'items' => [['type' => 'page', 'page_id' => $page->id, 'label' => ['uk' => 'Наша команда']]],
    ]);

    $label = MenuForm::loadRow($menu->id)['items'][0]['label'];

    expect($label)->toEqual(['en' => 'About us', 'uk' => 'Наша команда']);
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

it('saves the menu without leaving the edit form', function (): void {
    $menu = Menu::create(['name' => 'Main', 'key' => 'main', 'items' => []]);

    test()->actingAs(makeMenuAdmin(), 'web')->post(
        "/admin/menus/{$menu->id}/edit/forms/menuEditor",
        ['name' => 'Main renamed', 'key' => 'main', 'items' => []],
    )->assertRedirect();

    $effects = session('inertia.flash_data')['tbtop.effects'] ?? [];
    $kinds = collect($effects)->pluck('kind');

    expect($kinds)->toContain('notify')
        ->and($kinds)->not->toContain('redirect');
});

it('redirects to the new menu edit page after creating a menu', function (): void {
    test()->actingAs(makeMenuAdmin(), 'web')->post(
        '/admin/menus/create/forms/menuEditor',
        ['name' => 'Header', 'key' => 'header', 'items' => []],
    )->assertRedirect();

    $menu = Menu::query()->where('key', 'header')->firstOrFail();

    $effects = session('inertia.flash_data')['tbtop.effects'] ?? [];
    $redirect = collect($effects)->firstWhere('kind', 'redirect');
    expect($redirect)->not->toBeNull()
        ->and($redirect['href'])->toBe(route('tbtop.cms.menu-edit-page', ['menu' => $menu->id]));
});
