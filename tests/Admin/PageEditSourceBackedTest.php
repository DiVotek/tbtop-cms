<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\PageFormOptions;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\SourcedType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [HeroBlock::class]]);
    config()->set('tbtop-cms.pages.types', [SourcedType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function sourceBackedAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

it('excludes source-backed types from the create form options', function (): void {
    $options = PageFormOptions::types(includeSourceBacked: false);

    expect(array_column($options, 'value'))->not->toContain('sourced')
        ->and(array_column($options, 'value'))->toContain('page');
});

it('hides the block editor tabs and shows the Source card for a source-backed page', function (): void {
    $page = Page::create([
        'type' => 'sourced',
        'source_id' => 'thing-1',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Thing One'],
    ]);

    $json = test()->actingAs(sourceBackedAdmin(), 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json();

    $form = findNodeByKind($json['props']['structure'], 'form');
    $tabsNode = findNodeByKind($form, 'tabs');
    $tabs = $tabsNode['options']['tabs'];

    // General + SEO only — no per-block tabs, no "+ Add block" tab.
    expect(array_column($tabs, 'label'))->toBe(['General', 'SEO']);

    $keyValues = [];
    collectNodesByKind($form, 'displayKeyValue', $keyValues);
    expect($keyValues)->toHaveCount(1)
        ->and($keyValues[0]['options']['entries'])->toMatchArray([
            'Source ID' => 'thing-1',
            'Source' => 'Thing #thing-1',
        ]);
});

it('does not offer the type select for a source-backed page', function (): void {
    $page = Page::create([
        'type' => 'sourced',
        'source_id' => 'thing-1',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Thing One'],
    ]);

    $json = test()->actingAs(sourceBackedAdmin(), 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json();

    $form = findNodeByKind($json['props']['structure'], 'form');
    $names = [];
    collectFieldNames($form, $names);

    expect($names)->not->toContain('type');
});

it('leaves content null on save for a source-backed page', function (): void {
    $page = Page::create([
        'type' => 'sourced',
        'source_id' => 'thing-1',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Thing One'],
    ]);

    expect($page->content)->toBeNull();

    test()->actingAs(sourceBackedAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            'name' => ['en' => 'Thing One Updated'],
            'title' => ['en' => ''],
            'slug' => ['en' => 'thing-one'],
            'heading' => ['en' => ''],
            'summary' => ['en' => ''],
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
        ],
    )->assertRedirect()->assertSessionHasNoErrors();

    $page->refresh();

    expect($page->getTranslation('name', 'en'))->toBe('Thing One Updated')
        ->and($page->content)->toBeNull()
        ->and($page->type)->toBe('sourced');
});

it('rejects creating a page with a source-backed type submitted by hand', function (): void {
    $countBefore = Page::query()->count();

    test()->actingAs(sourceBackedAdmin(), 'web')->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => [
            'form' => [
                'name' => ['en' => 'Hand-made Thing'],
                'title' => ['en' => ''],
                'slug' => ['en' => 'hand-made-thing'],
                'type' => 'sourced',
                'parent_id' => 'none',
            ],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['type']);

    expect(Page::query()->count())->toBe($countBefore);
});

it('rejects retyping an existing content-only page into a source-backed type', function (): void {
    $page = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    test()->actingAs(sourceBackedAdmin(), 'web')
        ->from("/admin/pages/{$page->id}/edit")
        ->post(
            "/admin/pages/{$page->id}/edit/forms/pageEditor",
            [
                'name' => ['en' => 'About'],
                'title' => ['en' => ''],
                'slug' => ['en' => 'about'],
                'heading' => ['en' => ''],
                'summary' => ['en' => ''],
                'type' => 'sourced',
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
            ],
        )->assertSessionHasErrors('type');

    expect($page->refresh()->type)->toBe('page');
});

it('still shows block editing for a plain content-only page', function (): void {
    config()->set('tbtop-cms.pages.types', []);
    $page = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [['id' => 'hero1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Hi']]]],
    ]);

    $json = test()->actingAs(sourceBackedAdmin(), 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json();

    $form = findNodeByKind($json['props']['structure'], 'form');
    $tabsNode = findNodeByKind($form, 'tabs');
    $tabs = $tabsNode['options']['tabs'];

    expect(array_column($tabs, 'label'))->toBe(['General', 'SEO', 'Hero', '+ Add block']);
});
