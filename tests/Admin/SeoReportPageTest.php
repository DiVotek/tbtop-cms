<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
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
function seoReportTableNode(): array
{
    $structure = test()->get('/admin/seo-report', ['X-Inertia' => 'true'])
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
    $node = seoReportTableNode();

    expect($node['name'])->toBe('seoReport');
});

it('renders one table row per (page, locale, issue) the audit finds', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => ''],
        'title' => ['en' => ''],
        'seo' => [],
    ]);

    seoReportTableNode();

    $response = test()->getJson('/admin/seo-report/tables/seoReport')->assertOk();
    $rows = $response->json('data.data');

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row)->toHaveKeys(['severity', 'issue', 'page', 'locale', 'detail']);
    }
});

it('counts distinct pages in the summary, not distinct page labels', function (): void {
    // Two pages sharing one label: counting unique labels would report a
    // single affected page.
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Twin'], 'title' => ['en' => 'Twin'], 'seo' => []]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Twin'], 'title' => ['en' => 'Twin'], 'seo' => []]);

    $structure = test()->get('/admin/seo-report', ['X-Inertia' => 'true'])->assertOk()->json('props.structure');

    $alerts = [];
    $walk = function (array $node) use (&$walk, &$alerts): void {
        if (($node['kind'] ?? null) === 'displayAlert') {
            $alerts[] = $node;
        }
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            $walk($child);
        }
    };
    $walk($structure);

    expect($alerts)->not->toBeEmpty()
        ->and($alerts[0]['options']['message'] ?? '')
        ->toBe(__('tbtop-cms::seo_report.summary.problems', [
            'count' => 6, // per page: missing description (fail), duplicate title, missing og:image (warn)
            'pages' => 2,
        ]));
});

it('carries a localized title and breadcrumbs', function (): void {
    $response = test()->get('/admin/seo-report', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::seo_report.title'))
        ->and($response->json('props.breadcrumbs'))->toBe([
            ['label' => __('tbtop-cms::nav.groups.content')],
            ['label' => __('tbtop-cms::nav.items.seo_report')],
        ]);
});
