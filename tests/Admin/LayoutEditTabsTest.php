<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\LayoutOnlyBlock;
use Tbtop\Cms\Tests\Fixtures\RequiredFieldBlock;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

/**
 * Finds the tab whose label matches $label in a 'tabs' node's tab list and
 * returns its body node, or null if no such tab exists. Tab bodies live under
 * options.tabs[n].body (see S::tabs()/normalizeTab()), never under
 * options.children like a plain container.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>|null
 */
function findLayoutTabBody(array $node, string $label): ?array
{
    if (($node['kind'] ?? null) === 'tabs') {
        foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
            if (is_array($tab) && ($tab['label'] ?? null) === $label && is_array($tab['body'] ?? null)) {
                return $tab['body'];
            }
        }
    }

    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            $found = findLayoutTabBody($child, $label);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * Collects every field name found anywhere under $node (recursing through
 * containers, tab bodies, and rows), for asserting a tab's body carries only
 * its own kind's prefixed fields.
 *
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
function collectLayoutFieldNames(array $node): array
{
    $names = [];

    if (isset($node['name']) && is_string($node['name']) && ! in_array($node['kind'] ?? null, ['tabs', 'action'], true)) {
        $names[] = $node['name'];
    }

    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            $names = [...$names, ...collectLayoutFieldNames($child)];
        }
    }

    foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
        if (is_array($tab) && is_array($tab['body'] ?? null)) {
            $names = [...$names, ...collectLayoutFieldNames($tab['body'])];
        }
    }

    return $names;
}

/**
 * Collects every {name => label} action entry anywhere under $node.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, string|null>
 */
function collectLayoutActionNames(array $node): array
{
    $actions = [];

    if (($node['kind'] ?? null) === 'action') {
        $actions[$node['name']] = $node['options']['label'] ?? null;
    }

    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            $actions = [...$actions, ...collectLayoutActionNames($child)];
        }
    }

    foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
        if (is_array($tab) && is_array($tab['body'] ?? null)) {
            $actions = [...$actions, ...collectLayoutActionNames($tab['body'])];
        }
    }

    return $actions;
}

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function layoutTabsAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

it('renders one tab per stored block, in sort order, with only that kind\'s own fields in its body', function (): void {
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class, HeroBlock::class]]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'hero1', 'kind' => 'Hero', 'active' => true, 'sort' => 0, 'data' => ['title' => 'T']],
            ['id' => 'simple1', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'H', 'body' => 'B']],
        ],
    ]);

    $structure = test()->actingAs(layoutTabsAdmin(), 'web')
        ->get('/admin/layout/' . Slots::HEADER . '/edit', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $heroBody = findLayoutTabBody($structure, 'Hero');
    $simpleBody = findLayoutTabBody($structure, 'Simple');

    expect($heroBody)->not->toBeNull()
        ->and($simpleBody)->not->toBeNull();

    $heroFields = collectLayoutFieldNames($heroBody);
    $simpleFields = collectLayoutFieldNames($simpleBody);

    expect($heroFields)->toContain('block_hero1_title')
        ->and($heroFields)->not->toContain('block_simple1_heading')
        ->and($heroFields)->not->toContain('block_simple1_body')
        ->and($simpleFields)->toContain('block_simple1_heading')
        ->and($simpleFields)->toContain('block_simple1_body')
        ->and($simpleFields)->not->toContain('block_hero1_title');
});

it('saves edited prefixed values into the right block\'s data, preserving order and ids', function (): void {
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class, HeroBlock::class]]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'hero1', 'kind' => 'Hero', 'active' => true, 'sort' => 0, 'data' => ['title' => 'Old']],
            ['id' => 'simple1', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'H', 'body' => 'B']],
        ],
    ]);

    test()->actingAs(layoutTabsAdmin(), 'web')->postJson(
        '/admin/layout/' . Slots::HEADER . '/edit/forms/layoutEditor',
        [
            'block_hero1_active' => true, 'block_hero1_title' => ['en' => 'New'], 'block_hero1_sort' => 0,
            'block_simple1_active' => true, 'block_simple1_heading' => 'H2', 'block_simple1_body' => 'B2', 'block_simple1_sort' => 1,
        ],
    )->assertRedirect();

    $layout = Layout::query()->where('slot', Slots::HEADER)->firstOrFail();

    expect($layout->content)->toHaveCount(2)
        ->and($layout->content[0]['id'])->toBe('hero1')
        ->and($layout->content[0]['data']['title']['en'])->toBe('New')
        ->and($layout->content[1]['id'])->toBe('simple1')
        ->and($layout->content[1]['data'])->toBeIgnoringKeyOrder(['heading' => 'H2', 'body' => 'B2']);
});

it('reorders the persisted envelope when block_{id}_sort values change on save', function (): void {
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'a', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'A', 'body' => '']],
            ['id' => 'b', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'B', 'body' => '']],
        ],
    ]);

    test()->actingAs(layoutTabsAdmin(), 'web')->postJson(
        '/admin/layout/' . Slots::HEADER . '/edit/forms/layoutEditor',
        [
            'block_a_active' => true, 'block_a_heading' => 'A', 'block_a_body' => '', 'block_a_sort' => 5,
            'block_b_active' => true, 'block_b_heading' => 'B', 'block_b_body' => '', 'block_b_sort' => 1,
        ],
    )->assertRedirect();

    $layout = Layout::query()->where('slot', Slots::HEADER)->firstOrFail();

    expect(collect($layout->content)->pluck('id')->all())->toBe(['b', 'a']);
});

it('appends an inactive block of the chosen kind via the add action, creating the row when none existed', function (): void {
    // SimpleBlock defaults to Placement::PAGES, so its add action isn't
    // registered on the layout editor — use a layout-placed block instead.
    config(['tbtop-cms.blocks.types' => [LayoutOnlyBlock::class]]);

    expect(Layout::query()->where('slot', Slots::HEADER)->exists())->toBeFalse();

    test()->actingAs(layoutTabsAdmin(), 'web')->postJson(
        '/admin/layout/' . Slots::HEADER . '/edit/actions/layoutBlockAdd_LayoutOnly',
        ['payload' => ['form' => []]],
    )->assertOk();

    $layout = Layout::query()->where('slot', Slots::HEADER)->firstOrFail();

    expect($layout->content)->toHaveCount(1)
        ->and($layout->content[0]['kind'])->toBe('LayoutOnly')
        ->and($layout->content[0]['active'])->toBeFalse();
});

it('deletes exactly the targeted block via the remove action', function (): void {
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'a', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'A', 'body' => '']],
            ['id' => 'b', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'B', 'body' => '']],
        ],
    ]);

    test()->actingAs(layoutTabsAdmin(), 'web')->postJson(
        '/admin/layout/' . Slots::HEADER . '/edit/actions/layoutBlockRemove_a',
        ['payload' => ['form' => [
            'block_a_active' => true, 'block_a_heading' => 'A', 'block_a_body' => '', 'block_a_sort' => 0,
            'block_b_active' => true, 'block_b_heading' => 'B', 'block_b_body' => '', 'block_b_sort' => 1,
        ]]],
    )->assertOk();

    $layout = Layout::query()->where('slot', Slots::HEADER)->firstOrFail();

    expect(collect($layout->content)->pluck('id')->all())->toBe(['b']);
});

it('saves fine with an inactive block\'s required field left empty', function (): void {
    config(['tbtop-cms.blocks.types' => [RequiredFieldBlock::class]]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [['id' => 'r1', 'kind' => 'RequiredField', 'active' => false, 'sort' => 0, 'data' => ['value' => '']]],
    ]);

    test()->actingAs(layoutTabsAdmin(), 'web')->postJson(
        '/admin/layout/' . Slots::HEADER . '/edit/forms/layoutEditor',
        ['block_r1_active' => false, 'block_r1_value' => '', 'block_r1_sort' => 0],
    )->assertRedirect();

    // The blank string round-trips as null (Laravel's empty-string-to-null
    // conversion on a `nullable` field) — what this test asserts is that
    // save succeeded at all despite the required field being empty, not the
    // exact null-vs-'' representation.
    $content = Layout::query()->where('slot', Slots::HEADER)->firstOrFail()->content;
    expect($content)->toHaveCount(1)
        ->and($content[0]['id'])->toBe('r1')
        ->and($content[0]['data']['value'] ?? null)->toBeEmpty();
});

it('rejects saving an active block\'s empty required field', function (): void {
    config(['tbtop-cms.blocks.types' => [RequiredFieldBlock::class]]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [['id' => 'r1', 'kind' => 'RequiredField', 'active' => true, 'sort' => 0, 'data' => ['value' => '']]],
    ]);

    test()->actingAs(layoutTabsAdmin(), 'web')->postJson(
        '/admin/layout/' . Slots::HEADER . '/edit/forms/layoutEditor',
        ['block_r1_active' => true, 'block_r1_value' => '', 'block_r1_sort' => 0],
    )->assertUnprocessable()->assertJsonValidationErrors(['block_r1_value']);
});

it('round-trips an unregistered kind\'s data untouched through a save', function (): void {
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'ghost1', 'kind' => 'Ghost', 'active' => true, 'sort' => 0, 'data' => ['legacy' => 'kept']],
            ['id' => 'a', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'H', 'body' => 'B']],
        ],
    ]);

    $admin = layoutTabsAdmin();

    $structure = test()->actingAs($admin, 'web')
        ->get('/admin/layout/' . Slots::HEADER . '/edit', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    // An unregistered kind has no tab (the editor lists it under Problem
    // blocks), so the form never sends its fields; the save keeps it as stored.
    expect(findLayoutTabBody($structure, 'Ghost'))->toBeNull();

    test()->actingAs($admin, 'web')->postJson(
        '/admin/layout/' . Slots::HEADER . '/edit/forms/layoutEditor',
        [
            'block_a_active' => true, 'block_a_heading' => 'H2', 'block_a_body' => 'B2', 'block_a_sort' => 1,
        ],
    )->assertRedirect();

    $layout = Layout::query()->where('slot', Slots::HEADER)->firstOrFail();

    expect($layout->content[0]['id'])->toBe('ghost1')
        ->and($layout->content[0]['data'])->toBe(['legacy' => 'kept']);
});

it('uses the registered block\'s label on its tab, and gives an unregistered kind none', function (): void {
    config(['tbtop-cms.blocks.types' => [RequiredFieldBlock::class]]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'r1', 'kind' => 'RequiredField', 'active' => true, 'sort' => 0, 'data' => ['value' => 'x']],
            ['id' => 'ghost1', 'kind' => 'Ghost', 'active' => true, 'sort' => 1, 'data' => []],
        ],
    ]);

    $structure = test()->actingAs(layoutTabsAdmin(), 'web')
        ->get('/admin/layout/' . Slots::HEADER . '/edit', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    // RequiredFieldBlock::label() returns "Needs a value", not the raw kind
    // "RequiredField" nor its headline-cased form "Required Field" — this
    // proves the tab label comes from the registered block, not a fallback.
    expect(findLayoutTabBody($structure, 'Needs a value'))->not->toBeNull()
        ->and(findLayoutTabBody($structure, 'RequiredField'))->toBeNull()
        ->and(findLayoutTabBody($structure, 'Ghost'))->toBeNull();
});
