<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

/**
 * The critical round-trip check called out in the starter-blocks brief:
 * tpl_id is not a declared BlockContentField sub-field, so the admin
 * FormSubmitController's server-side $request->validate() strips it from
 * every content row before PageForm::save() ever sees the submitted form —
 * confirmed by inspecting RuleWalker::collect(), which only emits a rule per
 * declared repeater sub-field. PageForm::save() must therefore re-derive
 * tpl_id itself, from the page's OWN pre-save content, by matching each
 * block's 'id' — never trusting the submitted form to carry it.
 */
it('carries tpl_id across an existing-page save through the real form-submit endpoint, though the client-submitted row has no tpl_id key', function (): void {
    $page = Page::factory()->create([
        'content' => [
            ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'Hi'], 'tpl_id' => 'starter-tpl-1'],
        ],
    ]);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    test()->actingAs($user, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
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
            // Edited via the block tab — the payload has no tpl_id key at
            // all, matching what the real client actually posts.
            'block_blk1_heading' => 'Hi, edited',
            'block_blk1_active' => true,
            'block_blk1_sort' => 0,
        ],
    )->assertRedirect()->assertSessionHasNoErrors();

    $page->refresh();

    expect($page->content[0]['id'])->toBe('blk1')
        ->and($page->content[0]['data']['heading'])->toBe('Hi, edited')
        ->and($page->content[0]['tpl_id'])->toBe('starter-tpl-1');
});

it('carries tpl_id through PageForm::save called directly with a content row missing the key', function (): void {
    $page = Page::factory()->create([
        'content' => [
            ['id' => 'blk1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Old'], 'tpl_id' => 'starter-tpl-1'],
        ],
    ]);

    $saved = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'About'],
        'parent_id' => '',
        'content' => [[
            // Simulates the client round-trip: the row carries 'id' but no
            // 'tpl_id' key whatsoever.
            'id' => 'blk1',
            'kind' => 'Hero',
            'active' => true,
            'title' => 'New',
        ]],
    ], $page->id);

    expect($saved->content[0]['tpl_id'])->toBe('starter-tpl-1')
        ->and($saved->content[0]['data'])->toBe(['title' => 'New']);
});

it('does not invent a tpl_id for a block that never had one', function (): void {
    $page = Page::factory()->create([
        'content' => [['id' => 'blk1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Old']]],
    ]);

    $saved = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'About'],
        'parent_id' => '',
        'content' => [['id' => 'blk1', 'kind' => 'Hero', 'active' => true, 'title' => 'New']],
    ], $page->id);

    expect($saved->content[0])->not->toHaveKey('tpl_id');
});

it('drops a stale tpl_id when the block id changes (new block replacing the old one)', function (): void {
    $page = Page::factory()->create([
        'content' => [['id' => 'blk1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Old'], 'tpl_id' => 'starter-tpl-1']],
    ]);

    $saved = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'About'],
        'parent_id' => '',
        'content' => [['id' => 'blk-new', 'kind' => 'Hero', 'active' => true, 'title' => 'New']],
    ], $page->id);

    expect($saved->content[0]['id'])->toBe('blk-new')
        ->and($saved->content[0])->not->toHaveKey('tpl_id');
});
