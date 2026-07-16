<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
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

/** @return array<string, mixed> First node of the given kind in the page structure. */
function templatesTableNode(): array
{
    $structure = test()->get('/admin/templates', ['X-Inertia' => 'true'])
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

it('carries the create action in the page header, title, and subtitle from translations', function (): void {
    $response = test()->get('/admin/templates', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::templates.title'))
        ->and($response->json('props.subtitle'))->toBe(__('tbtop-cms::templates.subtitle'));

    $headerActions = $response->json('props.headerActions') ?? [];
    $found = collect($headerActions)->firstWhere('name', 'templateCreate');

    expect($found)->not->toBeNull();
});

it('no longer carries a create action on the table itself', function (): void {
    $node = templatesTableNode();

    expect($node['options']['headerActions'] ?? null)->toBeNull();
});
