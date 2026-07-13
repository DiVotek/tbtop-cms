<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);
    $this->actingAs($user, 'web');
});

/** @return array<string, mixed> First node of the given kind in the page structure. */
function languagesTableNode(): array
{
    $structure = test()->get('/admin/languages', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $found = null;
    $walk = function (array $node) use (&$walk, &$found): void {
        if (($node['kind'] ?? null) === 'table') {
            $found ??= $node;

            return;
        }
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            $walk($child);
        }
    };
    $walk($structure);

    expect($found)->not->toBeNull();

    return $found;
}

/** @return list<array<string, mixed>> Flattened field/action nodes inside the create form. */
function languageCreateFormChildren(): array
{
    $headerActions = test()->get('/admin/languages', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.headerActions') ?? [];

    $action = collect($headerActions)->firstWhere('name', 'languageCreate');
    expect($action)->not->toBeNull();

    $body = $action['options']['spec']['body'] ?? $action['spec']['body'] ?? null;
    expect($body)->not->toBeNull();

    $children = [];
    $collect = function (array $node) use (&$collect, &$children): void {
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $children[] = $child;
            $collect($child);
        }
    };
    $collect($body);

    return $children;
}

it('carries the create action in the page header, title, and subtitle from translations', function (): void {
    $response = test()->get('/admin/languages', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::languages.title'))
        ->and($response->json('props.subtitle'))->toBe(__('tbtop-cms::languages.subtitle'));

    $headerActions = $response->json('props.headerActions') ?? [];
    $found = collect($headerActions)->firstWhere('name', 'languageCreate');

    expect($found)->not->toBeNull();
});

it('no longer carries a create action on the table itself', function (): void {
    $node = languagesTableNode();

    expect($node['options']['headerActions'] ?? null)->toBeNull();
});

it('serializes the boolean flag fields with kind boolean in the create form', function (): void {
    $byName = array_column(languageCreateFormChildren(), null, 'name');

    expect($byName['is_default']['kind'])->toBe('boolean')
        ->and($byName['is_admin_active']['kind'])->toBe('boolean')
        ->and($byName['is_frontend_active']['kind'])->toBe('boolean');
});
