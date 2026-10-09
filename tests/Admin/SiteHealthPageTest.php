<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Health\HealthCheckRunner;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    test()->actingAs($user, 'web');
});

/** @return array<string, mixed> First node of the given kind in the page structure. */
function siteHealthTableNode(): array
{
    $structure = test()->get('/admin/site-health', ['X-Inertia' => 'true'])
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

it('is reachable for an authorized admin and renders a table', function (): void {
    $node = siteHealthTableNode();

    expect($node['name'])->toBe('siteHealth');
});

it('renders one table row per registered health check', function (): void {
    siteHealthTableNode();

    $response = test()->getJson('/admin/site-health/tables/siteHealth')->assertOk();
    $rows = $response->json('data.data');

    expect($rows)->toHaveCount(count(HealthCheckRunner::defaultChecks()));

    // A row carries only declared columns, so each check is identified by its label.
    $labels = array_map(static fn ($result): string => $result->label(), app(HealthCheckRunner::class)->run()->results);
    expect(collect($rows)->pluck('label')->all())->toEqualCanonicalizing($labels);
});

it('carries a localized title and breadcrumbs', function (): void {
    $response = test()->get('/admin/site-health', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::health.title'))
        ->and($response->json('props.breadcrumbs'))->toBe([
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => __('tbtop-cms::nav.items.site_health')],
        ]);
});
