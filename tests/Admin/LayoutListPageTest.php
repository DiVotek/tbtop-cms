<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);
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
