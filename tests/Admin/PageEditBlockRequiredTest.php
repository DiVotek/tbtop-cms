<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

/**
 * Block fields keep their `required` rule inside the page editor
 * (PrefixedField::rename() replays it under the prefixed name), so an explicit
 * Save rejects an empty required block field with an error keyed by that
 * prefixed field. The structural actions that sit inside the same form
 * ("+ Add block", "remove block") opt out of that gate — otherwise one
 * unconfigured block would veto every further edit to the page structure.
 */
beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [HeroBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function blockRequiredAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

/** @return array<string, mixed> */
function blockRequiredPayload(): array
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

function pageWithEmptyHero(): Page
{
    return Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About Us'],
        'content' => [
            ['id' => 'hero1', 'kind' => 'Hero', 'active' => false, 'sort' => 0, 'data' => ['title' => ['en' => '']]],
        ],
    ]);
}

it('still adds another block while an existing required block field is empty', function (): void {
    $page = pageWithEmptyHero();

    $response = test()->actingAs(blockRequiredAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockAdd_Hero",
        ['payload' => ['form' => [...blockRequiredPayload(), 'block_hero1_title' => ['en' => '']]]],
    );

    $response->assertOk();
    expect($page->refresh()->content)->toHaveCount(2);

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect', 'notify');
});

it('still removes a block while another required block field is empty', function (): void {
    $page = pageWithEmptyHero();
    $page->update(['content' => [
        ...$page->content,
        ['id' => 'hero2', 'kind' => 'Hero', 'active' => false, 'sort' => 1, 'data' => ['title' => ['en' => 'Second']]],
    ]]);

    test()->actingAs(blockRequiredAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockRemove_hero2",
        ['payload' => ['form' => [
            ...blockRequiredPayload(),
            'block_hero1_title' => ['en' => ''],
            'block_hero2_title' => ['en' => 'Second'],
        ]]],
    )->assertOk();

    expect(collect($page->refresh()->content)->pluck('id')->all())->toBe(['hero1']);
});

it('rejects Save with an error on the prefixed block field while it is empty', function (): void {
    $page = pageWithEmptyHero();

    test()->actingAs(blockRequiredAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...blockRequiredPayload(), 'name' => ['en' => 'Renamed'], 'block_hero1_title' => ['en' => '']],
    )->assertUnprocessable()->assertJsonValidationErrors(['block_hero1_title.en']);

    expect($page->refresh()->getTranslation('name', 'en'))->toBe('About Us');
});

it('keeps the required flag on the block field in the editor structure', function (): void {
    $page = pageWithEmptyHero();

    $structure = test()->actingAs(blockRequiredAdmin(), 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $field = null;
    $walk = function (array $node) use (&$walk, &$field): void {
        if (($node['name'] ?? null) === 'block_hero1_title') {
            $field ??= $node;

            return;
        }
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
        foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
            if (is_array($tab['body'] ?? null)) {
                $walk($tab['body']);
            }
        }
    };
    $walk($structure);

    expect($field)->not->toBeNull()
        ->and($field['options']['required'] ?? false)->toBeTrue();
});

it('saves the page once the required block field is filled', function (): void {
    $page = pageWithEmptyHero();

    test()->actingAs(blockRequiredAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...blockRequiredPayload(), 'block_hero1_title' => ['en' => 'Filled later']],
    )->assertRedirect();

    expect($page->refresh()->content[0]['data']['title']['en'] ?? null)->toBe('Filled later');
});
