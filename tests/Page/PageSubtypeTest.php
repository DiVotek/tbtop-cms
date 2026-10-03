<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Admin\Pages\Support\PageFormOptions;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\ContactsPageType;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class, HeroBlock::class]]);
    config(['tbtop-cms.pages.types' => [ContactsPageType::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

it('offers a page subtype under "Subtype", not under "Type"', function (): void {
    expect(array_column(PageFormOptions::types(), 'value'))->toBe(['page', 'category', 'article'])
        ->and(array_column(PageFormOptions::subtypes(), 'value'))->toBe(['', 'contacts']);
});

it('creates a page of the subtype when "Type" is page and a subtype is picked', function (): void {
    test()->actingAs(PageEditHelpers::admin(), 'web')->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => ['form' => [
            'name' => ['en' => 'Contacts'], 'title' => ['en' => ''], 'slug' => ['en' => 'contacts'],
            'type' => 'page', 'subtype' => 'contacts', 'parent_id' => 'none',
        ]],
    ])->assertOk();

    expect(Page::query()->where('type', 'contacts')->exists())->toBeTrue();
});

it('ignores the subtype when "Type" is not page, and keeps a plain page on the "none" subtype', function (): void {
    $category = PageForm::save(['name' => ['en' => 'Cat'], 'type' => 'category', 'subtype' => 'contacts', 'parent_id' => null]);
    $plain = PageForm::save(['name' => ['en' => 'Plain'], 'type' => 'page', 'subtype' => '', 'parent_id' => null]);

    expect($category->type)->toBe('category')->and($plain->type)->toBe('page');
});

it('loads a subtype page as Type = page + Subtype = its key, and a plain page with an empty subtype', function (): void {
    $contacts = Page::create(['type' => 'contacts', 'status' => PageStatus::Draft, 'name' => ['en' => 'Contacts']]);
    $plain = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Plain']]);

    $row = PageForm::loadRow($contacts->id);
    expect($row['type'])->toBe('page')->and($row['subtype'])->toBe('contacts')
        ->and(PageForm::loadRow($plain->id)['subtype'])->toBe('');
});

it('hides the Subtype select unless Type is page on the edit sidebar', function (): void {
    $page = Page::create(['type' => 'contacts', 'status' => PageStatus::Draft, 'name' => ['en' => 'Contacts']]);
    $structure = test()->actingAs(PageEditHelpers::admin(), 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])->assertOk()->json('props.structure');

    $field = findFieldByName($structure, 'subtype');
    expect($field)->not->toBeNull()
        ->and($field['meta']['hiddenIf'])->toMatchArray(['op' => 'neq', 'field' => 'type', 'value' => 'page']);
});
