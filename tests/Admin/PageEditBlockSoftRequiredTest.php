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
 * Block fields lose their `required` rule inside the page editor (see
 * PrefixedField::withoutRequired()): every form-scoped action ("+ Add block",
 * "remove block") runs the whole form's rules, and until the admin package
 * shows validation feedback for actions (CMS_TASKS.md A7), an empty required
 * block field would silently kill every action on the page. The live repro:
 * add an (empty) Hero to a page, then no further block can be added.
 */
beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [HeroBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function softRequiredAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

/** @return array<string, mixed> */
function softRequiredPayload(): array
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

    $response = test()->actingAs(softRequiredAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockAdd_Hero",
        ['payload' => ['form' => [...softRequiredPayload(), 'block_hero1_title' => ['en' => '']]]],
    );

    $response->assertOk();
    expect($page->refresh()->content)->toHaveCount(2);

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect', 'notify');
});

it('still saves the page while a required block field is empty', function (): void {
    $page = pageWithEmptyHero();

    test()->actingAs(softRequiredAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...softRequiredPayload(), 'name' => ['en' => 'Renamed'], 'block_hero1_title' => ['en' => '']],
    )->assertRedirect();

    expect($page->refresh()->getTranslation('name', 'en'))->toBe('Renamed');
});

it('keeps non-required block rules and drops the asterisk from the editor structure', function (): void {
    $page = pageWithEmptyHero();

    $structure = test()->actingAs(softRequiredAdmin(), 'web')
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
        ->and($field['options']['required'] ?? false)->toBeFalse();
});

it('block field values still round-trip through save without a required rule', function (): void {
    $page = pageWithEmptyHero();

    test()->actingAs(softRequiredAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...softRequiredPayload(), 'block_hero1_title' => ['en' => 'Filled later']],
    )->assertRedirect();

    expect($page->refresh()->content[0]['data']['title']['en'] ?? null)->toBe('Filled later');
});
