<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Seo\Models\SeoAuditSnapshot;
use Tbtop\Cms\Settings\Settings;
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

/** @return array<string, mixed> */
function seoReportStructure(): array
{
    return test()->get('/admin/seo-report', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');
}

/** A page with no title/name/description/og-image in `en`: 2 fail + 2 warn issues. */
function createBrokenPage(): Page
{
    return Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => ''],
        'title' => ['en' => ''],
        'seo' => [],
    ]);
}

it('carries a localized title and breadcrumbs', function (): void {
    $response = test()->get('/admin/seo-report', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::seo_report.title'))
        ->and($response->json('props.breadcrumbs'))->toBe([
            ['label' => __('tbtop-cms::nav.groups.content')],
            ['label' => __('tbtop-cms::nav.items.seo_report')],
        ]);
});

it('counts distinct pages in the summary, not distinct page labels', function (): void {
    // Two pages sharing one label: counting unique labels would report a
    // single affected page.
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Twin'], 'title' => ['en' => 'Twin'], 'seo' => []]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Twin'], 'title' => ['en' => 'Twin'], 'seo' => []]);

    $structure = seoReportStructure();

    $overviewTab = $structure['options']['tabs'][0]['body'];
    $alerts = [];
    collectNodesByKind($overviewTab, 'displayAlert', $alerts);

    expect($alerts)->not->toBeEmpty()
        ->and($alerts[0]['options']['message'] ?? '')
        ->toBe(__('tbtop-cms::seo_report.summary.problems', [
            'count' => 6, // per page: missing description (fail), duplicate title, missing og:image (warn)
            'pages' => 2,
        ]));
});

// ---------------------------------------------------------------------------
// Structure — tabs, stats, charts
// ---------------------------------------------------------------------------

it('renders two tabs with correct localized labels', function (): void {
    $structure = seoReportStructure();

    expect($structure['kind'])->toBe('tabs')
        ->and($structure['options']['tabs'])->toHaveCount(2)
        ->and($structure['options']['tabs'][0]['label'])->toBe(__('tbtop-cms::seo_report.tabs.overview'))
        ->and($structure['options']['tabs'][1]['label'])->toBe(__('tbtop-cms::seo_report.tabs.details'));
});

it('renders four stat cards on Overview with correct labels and values for a known-issue scenario', function (): void {
    createBrokenPage();

    $structure = seoReportStructure();
    $overviewTab = $structure['options']['tabs'][0]['body'];

    $stats = [];
    collectNodesByKind($overviewTab, 'stat', $stats);
    $byLabel = array_column(array_column($stats, 'options'), 'value', 'label');

    expect($stats)->toHaveCount(4)
        ->and($byLabel[__('tbtop-cms::seo_report.stats.pages_scanned')])->toBe(1)
        ->and($byLabel[__('tbtop-cms::seo_report.stats.pages_with_issues')])->toBe(1)
        ->and($byLabel[__('tbtop-cms::seo_report.severities.fail')])->toBe(2)
        ->and($byLabel[__('tbtop-cms::seo_report.severities.warn')])->toBe(2);
});

it('wires the severity donut and issue-type bar charts through ->query(), not static data', function (): void {
    createBrokenPage();

    $structure = seoReportStructure();
    $overviewTab = $structure['options']['tabs'][0]['body'];

    $donuts = [];
    collectNodesByKind($overviewTab, 'chart:donut', $donuts);
    $bars = [];
    collectNodesByKind($overviewTab, 'chart:bar', $bars);
    $lines = [];
    collectNodesByKind($overviewTab, 'chart:line', $lines);

    expect($donuts)->toHaveCount(1)
        ->and($donuts[0]['name'])->toBe('seo-report-by-severity')
        ->and($donuts[0]['options']['source'] ?? null)->toBe('seo-report-by-severity')
        ->and($bars)->toHaveCount(1)
        ->and($bars[0]['name'])->toBe('seo-report-by-issue-type')
        ->and($bars[0]['options']['source'] ?? null)->toBe('seo-report-by-issue-type')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['name'])->toBe('seo-report-history')
        ->and($lines[0]['options']['source'] ?? null)->toBe('seo-report-history');
});

it('serves correct rows from the severity donut and issue-type bar chart data endpoints', function (): void {
    createBrokenPage();
    seoReportStructure();

    $donutRows = test()->getJson('/admin/seo-report/data/seo-report-by-severity')->assertOk()->json('data');
    $byLabel = array_column($donutRows, 'value', 'label');

    expect($byLabel[__('tbtop-cms::seo_report.severities.fail')])->toBe(2)
        ->and($byLabel[__('tbtop-cms::seo_report.severities.warn')])->toBe(2);

    $barRows = test()->getJson('/admin/seo-report/data/seo-report-by-issue-type')->assertOk()->json('data');
    $byIssueLabel = array_column($barRows, 'value', 'label');

    expect($byIssueLabel[__('tbtop-cms::seo_report.issues.missing_title.label')])->toBe(1)
        ->and($byIssueLabel[__('tbtop-cms::seo_report.issues.missing_description.label')])->toBe(1)
        ->and($byIssueLabel[__('tbtop-cms::seo_report.issues.missing_translation.label')])->toBe(1)
        ->and($byIssueLabel[__('tbtop-cms::seo_report.issues.missing_og_image.label')])->toBe(1);
});

// ---------------------------------------------------------------------------
// Structure — Details tab
// ---------------------------------------------------------------------------

it('groups Details into one collapsed collapsible per page-with-issues, fail-containing first, with exact label text', function (): void {
    $broken = createBrokenPage(); // 2 fail + 2 warn
    $mild = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Beta'],
        'title' => ['en' => 'Beta title'],
        'seo' => ['description' => ['en' => 'Beta description']],
    ]); // 1 warn (missing_og_image)

    $structure = seoReportStructure();
    $detailsTab = $structure['options']['tabs'][1]['body'];

    $collapsibles = [];
    collectNodesByKind($detailsTab, 'collapsible', $collapsibles);

    expect($collapsibles)->toHaveCount(2);

    foreach ($collapsibles as $collapsible) {
        expect($collapsible['options']['collapsed'])->toBeTrue();
    }

    $brokenLabel = __('tbtop-cms::seo_report.untitled_page', ['id' => $broken->id]) . ' · '
        . trans_choice('tbtop-cms::seo_report.page_group.fail_count', 2, ['count' => '2']) . ' · '
        . trans_choice('tbtop-cms::seo_report.page_group.warn_count', 2, ['count' => '2']);
    $mildLabel = 'Beta title · ' . trans_choice('tbtop-cms::seo_report.page_group.warn_count', 1, ['count' => '1']);

    // Fail-containing group sorts first.
    expect($collapsibles[0]['options']['label'])->toBe($brokenLabel)
        ->and($collapsibles[1]['options']['label'])->toBe($mildLabel);

    $lists = [];
    collectNodesByKind($detailsTab, 'list', $lists);
    expect($lists)->toHaveCount(2);

    $brokenList = collect($lists)->first(fn (array $list): bool => count($list['options']['items']) === 4);
    $mildList = collect($lists)->first(fn (array $list): bool => count($list['options']['items']) === 1);

    expect($brokenList)->not->toBeNull()
        ->and($mildList)->not->toBeNull();

    foreach ($brokenList['options']['items'] as $item) {
        expect($item['title'])->not->toContain($broken->getTranslation('name', 'en', false) ?: 'Untitled')
            ->and($item['meta'])->not->toContain('Untitled page');
    }

    $ogItem = $mildList['options']['items'][0];
    expect($ogItem['title'])->toBe(__('tbtop-cms::seo_report.issues.missing_og_image.label'))
        ->and($ogItem['meta'])->toBe(__('tbtop-cms::seo_report.issues.missing_og_image.detail'))
        ->and($ogItem['meta'])->not->toContain('Beta')
        ->and($ogItem['title'])->not->toContain('Beta')
        ->and($ogItem['color'])->toBe('warning');
});

it('shows friendly empty content on both tabs and still writes an all-zero snapshot when the site has no pages', function (): void {
    $structure = seoReportStructure();

    $overviewTab = $structure['options']['tabs'][0]['body'];
    $alerts = [];
    collectNodesByKind($overviewTab, 'displayAlert', $alerts);
    expect($alerts[0]['options']['message'] ?? null)->toBe(__('tbtop-cms::seo_report.summary.clean'));

    $detailsTab = $structure['options']['tabs'][1]['body'];
    $detailsAlerts = [];
    collectNodesByKind($detailsTab, 'displayAlert', $detailsAlerts);
    $collapsibles = [];
    collectNodesByKind($detailsTab, 'collapsible', $collapsibles);

    expect($detailsAlerts)->not->toBeEmpty()
        ->and($detailsAlerts[0]['options']['message'] ?? null)->toBe(__('tbtop-cms::seo_report.empty.description'))
        ->and($collapsibles)->toBeEmpty();

    expect(SeoAuditSnapshot::query()->count())->toBe(1);
    $snapshot = SeoAuditSnapshot::query()->first();
    expect($snapshot->captured_on->toDateString())->toBe(today()->toDateString())
        ->and($snapshot->pages_scanned)->toBe(0)
        ->and($snapshot->pages_with_issues)->toBe(0)
        ->and($snapshot->fail_count)->toBe(0)
        ->and($snapshot->warn_count)->toBe(0)
        ->and($snapshot->issue_counts)->toBe([]);
});

// ---------------------------------------------------------------------------
// Daily snapshots
// ---------------------------------------------------------------------------

it('creates a snapshot row with correct counts when the report is opened', function (): void {
    createBrokenPage();

    seoReportStructure();

    expect(SeoAuditSnapshot::query()->count())->toBe(1);
    $snapshot = SeoAuditSnapshot::query()->first();

    expect($snapshot->captured_on->toDateString())->toBe(today()->toDateString())
        ->and($snapshot->pages_scanned)->toBe(1)
        ->and($snapshot->pages_with_issues)->toBe(1)
        ->and($snapshot->fail_count)->toBe(2)
        ->and($snapshot->warn_count)->toBe(2)
        ->and($snapshot->issue_counts)->toEqualCanonicalizing([
            'missing_title' => 1,
            'missing_description' => 1,
            'missing_og_image' => 1,
            'missing_translation' => 1,
        ]);
});

it('updates the same-day row instead of inserting a second one when opened twice', function (): void {
    createBrokenPage();
    seoReportStructure();

    expect(SeoAuditSnapshot::query()->count())->toBe(1);

    // A site-wide default og:image plus a second, otherwise-fully-filled-in
    // page: the second open sees 2 pages scanned, only the first still broken.
    app(Settings::class)->set(
        'seo.default_og_image',
        (string) Media::create([
            'name' => 'og.jpg', 'disk' => 'public', 'path' => 'media/og.jpg', 'mime' => 'image/jpeg', 'size' => 100,
        ])->getKey(),
    );
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Clean'],
        'title' => ['en' => 'Clean title'],
        'seo' => ['description' => ['en' => 'Clean description']],
    ]);

    seoReportStructure();

    expect(SeoAuditSnapshot::query()->count())->toBe(1);
    $snapshot = SeoAuditSnapshot::query()->first();
    expect($snapshot->pages_scanned)->toBe(2)
        ->and($snapshot->pages_with_issues)->toBe(1);
});

it('writes/updates today\'s snapshot via the artisan command, which is not auto-scheduled', function (): void {
    createBrokenPage();

    test()->artisan('cms:seo:snapshot')->assertExitCode(0);

    expect(SeoAuditSnapshot::query()->count())->toBe(1);
    $snapshot = SeoAuditSnapshot::query()->first();
    expect($snapshot->captured_on->toDateString())->toBe(today()->toDateString())
        ->and($snapshot->pages_scanned)->toBe(1)
        ->and($snapshot->fail_count)->toBe(2)
        ->and($snapshot->warn_count)->toBe(2);

    $summaries = array_map(
        static fn ($event): string => $event->getSummaryForDisplay(),
        app(Schedule::class)->events(),
    );

    expect($summaries)->not->toContain('cms:seo:snapshot');
});

it('keys snapshot issue counts by a locale-independent issue key', function (): void {
    createBrokenPage();

    app()->setLocale('uk');
    test()->artisan('cms:seo:snapshot')->assertExitCode(0);
    $fromUk = SeoAuditSnapshot::query()->firstOrFail()->issue_counts;

    app()->setLocale('en');
    test()->artisan('cms:seo:snapshot')->assertExitCode(0);
    $fromEn = SeoAuditSnapshot::query()->firstOrFail()->issue_counts;

    expect($fromUk)->toEqualCanonicalizing([
        'missing_title' => 1,
        'missing_description' => 1,
        'missing_og_image' => 1,
        'missing_translation' => 1,
    ])->and($fromEn)->toEqualCanonicalizing($fromUk);
});

it('leaves the seeded error pages out of the audit and the pages-scanned counts', function (): void {
    // Error pages are never served at a URL (the sitemap skips them too), so
    // their noindex flag and missing description are not issues to report.
    foreach ([ErrorClass::ClientError, ErrorClass::ServerError] as $class) {
        Page::factory()->create([
            'status' => PageStatus::Published,
            'is_system' => true,
            'is_indexable' => false,
            'error_class' => $class,
            'name' => ['en' => 'Error'],
            'seo' => [],
        ]);
    }

    $overviewTab = seoReportStructure()['options']['tabs'][0]['body'];
    $stats = [];
    collectNodesByKind($overviewTab, 'stat', $stats);
    $byLabel = array_column(array_column($stats, 'options'), 'value', 'label');

    expect($byLabel[__('tbtop-cms::seo_report.stats.pages_scanned')])->toBe(0)
        ->and($byLabel[__('tbtop-cms::seo_report.stats.pages_with_issues')])->toBe(0);

    test()->artisan('cms:seo:snapshot')->assertExitCode(0);

    expect(SeoAuditSnapshot::query()->first()->pages_scanned)->toBe(0);
});
