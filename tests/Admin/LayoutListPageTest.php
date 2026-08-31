<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

/**
 * Recursively walks a structure node, collecting action names/labels and, in
 * document order, every displayText/displayValue leaf's text — so tests can
 * assert both action wiring and rendered label ordering from one walk.
 *
 * @param  array<string, mixed>  $node
 * @param  array<string, string|null>  $actionNames
 * @param  list<string>  $texts
 */
function walkLayoutStructure(array $node, array &$actionNames, array &$texts): void
{
    $kind = $node['kind'] ?? null;

    if ($kind === 'action') {
        $actionNames[$node['name']] = $node['options']['label'] ?? null;
    }

    if ($kind === 'displayText' && is_string($node['options']['content'] ?? null)) {
        $texts[] = $node['options']['content'];
    }

    if ($kind === 'displayValue' && is_string($node['options']['value'] ?? null)) {
        $texts[] = $node['options']['value'];
    }

    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            walkLayoutStructure($child, $actionNames, $texts);
        }
    }
}

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    $this->actingAs($user, 'web');
});

it('carries title and subtitle from translations', function (): void {
    $response = test()->get('/admin/layout', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::layout.title'))
        ->and($response->json('props.subtitle'))->toBe(__('tbtop-cms::layout.subtitle'));
});

it('labels each slot row edit action from translations', function (): void {
    $structure = test()->get('/admin/layout', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $actionNames = [];
    $walk = function (array $node) use (&$walk, &$actionNames): void {
        if (($node['kind'] ?? null) === 'action') {
            $actionNames[$node['name']] = $node['options']['label'] ?? null;
        }
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
    };
    $walk($structure);

    foreach (Slots::all() as $slot) {
        expect($actionNames)->toHaveKey('edit-' . $slot)
            ->and($actionNames['edit-' . $slot])->toBe(__('tbtop-cms::layout.actions.edit'));
    }
});

it('renders blocks in stored content order, not alphabetical', function (): void {
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class, HeroBlock::class]]);

    Layout::create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'a', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'H', 'body' => 'B']],
            ['id' => 'b', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'T']],
        ],
    ]);

    $structure = test()->get('/admin/layout', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $actionNames = [];
    $texts = [];
    walkLayoutStructure($structure, $actionNames, $texts);

    $simpleIndex = array_search('1. Simple', $texts, true);
    $heroIndex = array_search('2. Hero', $texts, true);

    expect($simpleIndex)->not->toBeFalse()
        ->and($heroIndex)->not->toBeFalse()
        ->and($simpleIndex)->toBeLessThan($heroIndex);
});

it('shows an inactive block with its label and the inactive badge', function (): void {
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);

    Layout::create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'a', 'kind' => 'Simple', 'active' => false, 'data' => ['heading' => 'H', 'body' => 'B']],
        ],
    ]);

    $structure = test()->get('/admin/layout', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $actionNames = [];
    $texts = [];
    walkLayoutStructure($structure, $actionNames, $texts);

    expect($texts)->toContain('1. Simple')
        ->and($texts)->toContain(__('tbtop-cms::layout.blocks.inactive_badge'));
});

it('shows the empty state for slots with no saved layout row', function (): void {
    $structure = test()->get('/admin/layout', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $actionNames = [];
    $texts = [];
    walkLayoutStructure($structure, $actionNames, $texts);

    $emptyCount = count(array_filter($texts, fn (string $text): bool => $text === __('tbtop-cms::layout.empty')));

    expect($emptyCount)->toBe(count(Slots::all()))
        ->and($texts)->not->toContain('1. Simple')
        ->and($texts)->not->toContain('1. Hero');
});
