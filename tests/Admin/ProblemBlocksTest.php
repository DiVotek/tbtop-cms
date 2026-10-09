<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

/**
 * The first node anywhere in $tree (modal bodies included) whose `name` is $name.
 *
 * @param  array<array-key, mixed>  $tree
 * @return array<string, mixed>|null
 */
function problemNode(array $tree, string $name): ?array
{
    if (($tree['name'] ?? null) === $name) {
        return $tree;
    }
    foreach ($tree as $child) {
        if (is_array($child) && ($found = problemNode($child, $name)) !== null) {
            return $found;
        }
    }

    return null;
}

/** @return list<array<string, mixed>> a healthy row, an unregistered kind and a reference to a deleted shared block */
function problemContent(): array
{
    $gone = SharedBlock::query()->create(['name' => 'Promo', 'kind' => 'Simple', 'data' => []]);
    $goneId = (int) $gone->getKey();
    $gone->delete();

    return [
        ['id' => 'ok1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'Kept']],
        ['id' => 'ghost1', 'kind' => 'Ghost', 'active' => true, 'sort' => 1, 'data' => ['legacy' => 'value']],
        ['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'sort' => 2, 'shared' => $goneId, 'data' => []],
    ];
}

function problemPage(array $content): Page
{
    return Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'About Us'], 'content' => $content]);
}

it('lists the rows the site skips under a counted action, with kind, reason and saved data, and gives them no tab', function (): void {
    $content = problemContent();
    $page = problemPage($content);

    $structure = PageEditHelpers::structure($page->id)['props']['structure'];
    $action = problemNode($structure, 'pageProblemBlocks');

    expect($action)->not->toBeNull()
        ->and($action['options']['label'])->toBe('Problem blocks (2)')
        ->and(collectTabLabels($structure))->toBe(['General', 'SEO', 'Simple', '+ Add block']);

    $modal = json_encode($action, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    expect($modal)->toContain('Ghost')
        ->toContain('The block [Ghost] no longer exists in the code.')
        ->toContain("Shared block #{$content[2]['shared']} no longer exists.")
        ->toContain('&quot;legacy&quot;: &quot;value&quot;')
        ->and(problemNode($action, 'pageProblemDelete_ghost1'))->not->toBeNull()
        ->and(problemNode($action, 'pageProblemDelete_ref1'))->not->toBeNull()
        ->and(problemNode($action, 'pageProblemDelete_ok1'))->toBeNull();
});

it('names a shared block of another kind as the reason', function (): void {
    $other = SharedBlock::query()->create(['name' => 'Banner', 'kind' => 'Hero', 'data' => []]);
    $page = problemPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $other->id, 'data' => []]]);

    $action = problemNode(PageEditHelpers::structure($page->id)['props']['structure'], 'pageProblemBlocks');

    expect(json_encode($action, JSON_UNESCAPED_UNICODE))->toContain("Shared block #{$other->id} is a different kind.");
});

it('shows no problem-blocks action on a page whose rows all render', function (): void {
    $page = problemPage([['id' => 'ok1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'Hi']]]);

    expect(problemNode(PageEditHelpers::structure($page->id)['props']['structure'], 'pageProblemBlocks'))->toBeNull();
});

it('deletes exactly the chosen problem row and keeps every other row as it was', function (): void {
    $content = problemContent();
    $page = problemPage($content);

    $response = test()->actingAs(PageEditHelpers::admin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageProblemDelete_ghost1",
        ['payload' => ['form' => [
            ...PageEditHelpers::baseEditPayload(),
            'status' => PageStatus::Draft->value,
            'lock_version' => 0,
            'block_ok1_heading' => 'Kept',
            'block_ok1_active' => true,
            'block_ok1_sort' => 0,
        ]]],
    );

    $response->assertOk();
    $saved = $page->refresh()->content;
    expect(array_column($saved, 'id'))->toBe(['ok1', 'ref1'])
        ->and($saved[0]['data'])->toBe(['heading' => 'Kept'])
        ->and($saved[1]['shared'])->toBe($content[2]['shared'])
        ->and(collect($response->json('effects'))->pluck('kind')->all())->toBe(['closeModal', 'notify', 'redirect']);
});

it('keeps the row and the modal open when the form is stale', function (): void {
    $page = problemPage(problemContent());
    Page::query()->whereKey($page->id)->update(['lock_version' => 1]);

    $response = test()->actingAs(PageEditHelpers::admin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageProblemDelete_ghost1",
        ['payload' => ['form' => [...PageEditHelpers::baseEditPayload(), 'status' => PageStatus::Draft->value, 'lock_version' => 0]]],
    );

    $response->assertOk();
    expect(array_column($page->refresh()->content, 'id'))->toBe(['ok1', 'ghost1', 'ref1'])
        ->and(collect($response->json('effects'))->pluck('kind')->all())->toBe(['haltModal']);
});

it('lists and deletes a problem row of a layout slot the same way', function (): void {
    Layout::query()->create(['slot' => Slots::HEADER, 'content' => [
        ['id' => 'ok1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'H', 'body' => 'B']],
        ['id' => 'ghost1', 'kind' => 'Ghost', 'active' => false, 'sort' => 1, 'data' => ['legacy' => 'value']],
    ]]);
    $admin = PageEditHelpers::admin();

    $structure = test()->actingAs($admin, 'web')
        ->get('/admin/layout/' . Slots::HEADER . '/edit', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');
    $action = problemNode($structure, 'layoutProblemBlocks');
    expect($action['options']['label'] ?? null)->toBe('Problem blocks (1)')
        ->and(json_encode($action, JSON_UNESCAPED_UNICODE))->toContain('The block [Ghost] no longer exists in the code.')
        ->and(Arr::first(collectTabLabels($structure), fn (string $label): bool => $label === 'Ghost'))->toBeNull();

    test()->actingAs($admin, 'web')->postJson(
        '/admin/layout/' . Slots::HEADER . '/edit/actions/layoutProblemDelete_ghost1',
        ['payload' => ['form' => ['block_ok1_active' => true, 'block_ok1_heading' => 'H', 'block_ok1_body' => 'B', 'block_ok1_sort' => 0]]],
    )->assertOk();

    $saved = Layout::query()->where('slot', Slots::HEADER)->firstOrFail()->content;
    expect(array_column($saved, 'id'))->toBe(['ok1'])
        ->and($saved[0]['data'])->toBeIgnoringKeyOrder(['heading' => 'H', 'body' => 'B']);
});

/**
 * @param  array<array-key, mixed>  $tree
 * @return list<string>
 */
function collectTabLabels(array $tree): array
{
    $labels = [];
    if (($tree['kind'] ?? null) === 'tabs') {
        $labels = array_column((array) ($tree['options']['tabs'] ?? []), 'label');
    }
    foreach ($tree as $child) {
        if (is_array($child)) {
            $labels = [...$labels, ...collectTabLabels($child)];
        }
    }

    return $labels;
}
