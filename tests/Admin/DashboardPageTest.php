<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Template\Models\Template;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    Cache::flush();
});

function actingAsDashboardAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);
    test()->actingAs($user, 'web');

    return $user;
}

function submissionOn(LeadStatus $lead, string | Carbon $when): FormSubmission
{
    return FormSubmission::query()->create([
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada'],
        'status' => SubmissionStatus::Handled,
        'lead_status' => $lead,
        'created_at' => $when,
    ]);
}

/** @return array<string, mixed> */
function dashboardStatValues(): array
{
    $structure = test()->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $stats = [];
    collectDashboardNodesByKind($structure, 'stat', $stats);

    return array_column($stats, 'value', 'label');
}

/** @param array<string, mixed> $node @param list<array<string, mixed>> $out */
function collectDashboardNodesByKind(array $node, string $kind, array &$out): void
{
    if (($node['kind'] ?? null) === $kind) {
        $out[] = $node['options'] ?? [];
    }

    foreach ($node['options']['children'] ?? [] as $child) {
        if (is_array($child)) {
            collectDashboardNodesByKind($child, $kind, $out);
        }
    }
}

/** @return list<array<string, mixed>> */
function dashboardStructureNodesOfKind(string $kind): array
{
    $structure = test()->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $nodes = [];
    collectDashboardNodesByKind($structure, $kind, $nodes);

    return $nodes;
}

it('shows the dashboard to an admin user', function (): void {
    actingAsDashboardAdmin();

    $this->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk();
});

it('redirects a guest away from the dashboard', function (): void {
    $this->get('/admin/dashboard')->assertRedirect(route('login'));
});

it('renders an empty dashboard without error', function (): void {
    actingAsDashboardAdmin();

    $values = dashboardStatValues();

    expect($values[__('tbtop-cms::dashboard.stats.pages')])->toBe(0)
        ->and($values[__('tbtop-cms::dashboard.stats.submissions_new')])->toBe(0)
        ->and($values[__('tbtop-cms::dashboard.stats.media')])->toBe(0)
        ->and($values[__('tbtop-cms::dashboard.stats.templates_layouts')])->toBe(0);
});

it('counts pages by status for the pages stat', function (): void {
    actingAsDashboardAdmin();
    Page::factory()->create(['status' => PageStatus::Published]);
    Page::factory()->create(['status' => PageStatus::Published]);
    Page::factory()->create(['status' => PageStatus::Draft]);

    $values = dashboardStatValues();

    expect($values[__('tbtop-cms::dashboard.stats.pages')])->toBe(3);
});

it('flags needs-review submissions and highlights the stat when unread leads exist', function (): void {
    actingAsDashboardAdmin();
    submissionOn(LeadStatus::New, now());
    submissionOn(LeadStatus::New, now());
    submissionOn(LeadStatus::Processed, now());

    $structure = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $stats = [];
    collectDashboardNodesByKind($structure, 'stat', $stats);
    $submissionsStat = collect($stats)->firstWhere('label', __('tbtop-cms::dashboard.stats.submissions_new'));

    expect($submissionsStat['value'])->toBe(2)
        ->and($submissionsStat['color'])->toBe('warning');
});

it('counts media files and total size for the media stat', function (): void {
    actingAsDashboardAdmin();
    Media::query()->create(['name' => 'a.png', 'disk' => 'public', 'path' => 'a.png', 'mime' => 'image/png', 'size' => 1000]);
    Media::query()->create(['name' => 'b.png', 'disk' => 'public', 'path' => 'b.png', 'mime' => 'image/png', 'size' => 2000]);

    $values = dashboardStatValues();

    expect($values[__('tbtop-cms::dashboard.stats.media')])->toBe(2);
});

it('sums templates and layouts for the combined stat', function (): void {
    actingAsDashboardAdmin();
    Template::query()->create(['name' => 'T1']);
    Layout::query()->create(['slot' => 'header']);
    Layout::query()->create(['slot' => 'footer']);

    $values = dashboardStatValues();

    expect($values[__('tbtop-cms::dashboard.stats.templates_layouts')])->toBe(3);
});

it('returns lead status breakdown for the donut chart data endpoint', function (): void {
    actingAsDashboardAdmin();
    submissionOn(LeadStatus::New, now());
    submissionOn(LeadStatus::New, now());
    submissionOn(LeadStatus::Processed, now());

    $data = $this->getJson('/admin/dashboard/data/leads-by-status')->assertOk()->json('data');

    $byLabel = array_column($data, 'value', 'label');

    expect($byLabel[__('tbtop-cms::dashboard.lead_status.new')])->toBe(2)
        ->and($byLabel[__('tbtop-cms::dashboard.lead_status.processed')])->toBe(1)
        ->and($byLabel[__('tbtop-cms::dashboard.lead_status.read')])->toBe(0)
        ->and($byLabel[__('tbtop-cms::dashboard.lead_status.discarded')])->toBe(0);
});

it('buckets submissions per day for the area chart within the default 30-day period', function (): void {
    actingAsDashboardAdmin();
    submissionOn(LeadStatus::New, now());
    submissionOn(LeadStatus::New, now());
    submissionOn(LeadStatus::New, now()->subDay());

    $data = $this->getJson('/admin/dashboard/data/submissions-daily')->assertOk()->json('data');

    expect($data)->toHaveCount(30);
    $byDate = array_column($data, 'value', 'date');
    expect($byDate[now()->toDateString()])->toBe(2)
        ->and($byDate[now()->subDay()->toDateString()])->toBe(1);
});

it('respects a custom period param on the area chart endpoint', function (): void {
    actingAsDashboardAdmin();
    submissionOn(LeadStatus::New, now());

    $data = $this->getJson('/admin/dashboard/data/submissions-daily?period=7')->assertOk()->json('data');

    expect($data)->toHaveCount(7);
});

it('falls back to the default period for an invalid period param', function (): void {
    actingAsDashboardAdmin();

    $data = $this->getJson('/admin/dashboard/data/submissions-daily?period=999')->assertOk()->json('data');

    expect($data)->toHaveCount(30);
});

it('maps chart node options to the query row shape for the client renderers', function (): void {
    actingAsDashboardAdmin();

    $donuts = dashboardStructureNodesOfKind('chart:donut');
    $areas = dashboardStructureNodesOfKind('chart:area');

    expect($donuts)->toHaveCount(1)
        ->and($donuts[0]['nameKey'] ?? null)->toBe('label')
        ->and($donuts[0]['series'][0]['dataKey'] ?? null)->toBe('value')
        ->and($areas)->toHaveCount(1)
        ->and($areas[0]['xKey'] ?? null)->toBe('date')
        ->and($areas[0]['series'][0]['dataKey'] ?? null)->toBe('value')
        ->and($areas[0]['series'][0]['label'] ?? null)->toBe(__('tbtop-cms::dashboard.charts.series_submissions'));
});

// ---------------------------------------------------------------------------
// tbtop/admin 0.2.14 widget options
// ---------------------------------------------------------------------------

it('sets a subtitle and header actions on the dashboard page props', function (): void {
    actingAsDashboardAdmin();

    $props = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props');

    $names = array_column($props['headerActions'], 'name');

    expect($props['subtitle'])->toBe(__('tbtop-cms::dashboard.welcome'))
        ->and($names)->toContain('dashboardRefresh', 'dashboardViewSite', 'dashboardNewPage');
});

it('opens View site as a new-tab visit action to the site root', function (): void {
    actingAsDashboardAdmin();

    $headerActions = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.headerActions');

    $viewSite = collect($headerActions)->firstWhere('name', 'dashboardViewSite');

    expect($viewSite['options']['spec']['type'])->toBe('visit')
        ->and($viewSite['options']['spec']['href'])->toBe(url('/'))
        ->and($viewSite['options']['spec']['newTab'])->toBeTrue();
});

it('pins the KPI stat sparklines to the bottom of the card', function (): void {
    actingAsDashboardAdmin();
    Page::factory()->create(['status' => PageStatus::Published]);

    $structure = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $stats = [];
    collectDashboardNodesByKind($structure, 'stat', $stats);
    $pagesStat = collect($stats)->firstWhere('label', __('tbtop-cms::dashboard.stats.pages'));

    expect($pagesStat['sparklinePosition'])->toBe('bottom')
        ->and($pagesStat['sparkline'])->toHaveCount(14)
        ->and(array_sum($pagesStat['sparkline']))->toBe(1);
});

// ---------------------------------------------------------------------------
// Recent activity — card-list rows (replaces embedded tables)
// ---------------------------------------------------------------------------

it('renders recently updated pages as a card-list with status color and an "open" action', function (): void {
    actingAsDashboardAdmin();
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Home']]);
    Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'About']]);

    $structure = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $sections = [];
    collectDashboardNodesByKind($structure, 'section', $sections);
    $pagesCard = collect($sections)->firstWhere('title', __('tbtop-cms::dashboard.recent.pages_title'));

    expect($pagesCard['variant'])->toBe('card')
        ->and($pagesCard['action'])->toBe([
            'label' => __('tbtop-cms::dashboard.recent.open_pages'),
            'url' => route('tbtop.cms.pages-page'),
        ]);

    $lists = [];
    collectDashboardNodesByKind($structure, 'list', $lists);
    $pagesList = collect($lists)->first(fn (array $list): bool => collect($list['items'])->contains('title', 'Home'));

    $colorByTitle = array_column($pagesList['items'], 'color', 'title');
    expect($colorByTitle['Home'])->toBe('success')
        ->and($colorByTitle['About'])->toBe('muted')
        ->and($pagesList['items'][0]['url'])->toBe(route('tbtop.cms.pages-page'));
});

it('caps recently updated pages and latest submissions at 5 items each', function (): void {
    actingAsDashboardAdmin();
    Page::factory()->count(8)->create(['status' => PageStatus::Published]);
    for ($i = 0; $i < 8; $i++) {
        submissionOn(LeadStatus::New, now()->subMinutes($i));
    }

    // Recent-activity lists cap at 5; the two environment-info lists are
    // fixed at 4 static rows each, so only check lists with >4 items.
    $lists = dashboardStructureNodesOfKind('list');
    $recentLists = array_filter($lists, static fn (array $list): bool => count($list['items']) > 4);

    expect($recentLists)->toHaveCount(2);
    foreach ($recentLists as $list) {
        expect($list['items'])->toHaveCount(5);
    }
});

it('colors the latest-submissions list by lead status', function (): void {
    actingAsDashboardAdmin();
    submissionOn(LeadStatus::New, now());
    submissionOn(LeadStatus::Processed, now()->subMinute());
    submissionOn(LeadStatus::Read, now()->subMinutes(2));
    submissionOn(LeadStatus::Discarded, now()->subMinutes(3));

    $structure = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $sections = [];
    collectDashboardNodesByKind($structure, 'section', $sections);
    $submissionsCard = collect($sections)->firstWhere('title', __('tbtop-cms::dashboard.recent.submissions_title'));

    expect($submissionsCard['variant'])->toBe('card')
        ->and($submissionsCard['action'])->toBe([
            'label' => __('tbtop-cms::dashboard.recent.open_submissions'),
            'url' => route('tbtop.cms.form-submissions-page'),
        ]);

    $lists = [];
    collectDashboardNodesByKind($structure, 'list', $lists);
    $submissionsList = collect($lists)->first(
        fn (array $list): bool => $list['items'] !== []
            && collect($list['items'])->pluck('title')->every(fn ($t) => $t === 'ContactForm'),
    );
    $colors = collect($submissionsList['items'])->pluck('color')->all();

    expect($colors)->toContain('danger', 'success', 'muted');
});

// ---------------------------------------------------------------------------
// System — live-polling metrics
// ---------------------------------------------------------------------------

it('exposes CPU, memory, and disk as polling stat cards with bottom sparklines', function (): void {
    actingAsDashboardAdmin();

    $structure = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $stats = [];
    collectDashboardNodesByKind($structure, 'stat', $stats);

    foreach (['cpu_title', 'memory_title', 'disk_title'] as $key) {
        $stat = collect($stats)->firstWhere('label', __('tbtop-cms::dashboard.system.' . $key));

        expect($stat)->not->toBeNull()
            ->and($stat['poll'])->toBe(10)
            ->and($stat['source'])->toBe(__('tbtop-cms::dashboard.system.' . $key))
            ->and($stat['sparklinePosition'])->toBe('bottom')
            ->and($stat['sparklineColor'])->toBe('success')
            ->and($stat['sparkline'])->toBeArray()
            ->and($stat['trend'])->toBeIn(['up', 'down'])
            ->and($stat['value'])->toBeString();
    }
});

it('answers each system metric data endpoint with a fresh value and growing sparkline', function (): void {
    actingAsDashboardAdmin();
    $label = __('tbtop-cms::dashboard.system.cpu_title');

    // Render once so the ring buffer has a first sample, then poll twice more.
    $this->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk();

    $first = $this->getJson('/admin/dashboard/data/' . rawurlencode($label))->assertOk();
    $second = $this->getJson('/admin/dashboard/data/' . rawurlencode($label))->assertOk();

    expect($first->json('data'))->toHaveKeys(['value', 'sparkline'])
        ->and($first->json('data.value'))->toBeString()
        ->and(count($second->json('data.sparkline')))->toBeGreaterThan(count($first->json('data.sparkline')) - 1);
});

it('caps each system metric sparkline history at 30 samples', function (): void {
    actingAsDashboardAdmin();
    $label = __('tbtop-cms::dashboard.system.disk_title');

    for ($i = 0; $i < 35; $i++) {
        $this->getJson('/admin/dashboard/data/' . rawurlencode($label))->assertOk();
    }

    $data = $this->getJson('/admin/dashboard/data/' . rawurlencode($label))->assertOk()->json('data');

    expect(count($data['sparkline']))->toBeLessThanOrEqual(30);
});

// ---------------------------------------------------------------------------
// System — environment info card-lists
// ---------------------------------------------------------------------------

it('splits environment info into versions and infrastructure card-lists', function (): void {
    actingAsDashboardAdmin();

    $structure = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $sections = [];
    collectDashboardNodesByKind($structure, 'section', $sections);
    $versions = collect($sections)->firstWhere('title', __('tbtop-cms::dashboard.system.versions_title'));
    $infrastructure = collect($sections)->firstWhere('title', __('tbtop-cms::dashboard.system.infrastructure_title'));

    expect($versions['variant'])->toBe('card')
        ->and($infrastructure['variant'])->toBe('card');

    $lists = [];
    collectDashboardNodesByKind($structure, 'list', $lists);
    $titles = collect($lists)->flatMap(fn (array $list): array => array_column($list['items'], 'title'))->all();

    expect($titles)->toContain(
        __('tbtop-cms::dashboard.system.php_version'),
        __('tbtop-cms::dashboard.system.db_driver'),
    );

    $allItems = collect($lists)->flatMap(fn (array $list): array => $list['items']);
    expect($allItems->pluck('color')->unique()->all())->toBe(['muted']);
});

// ---------------------------------------------------------------------------
// Route-availability guards
// ---------------------------------------------------------------------------

it('gives each recent-activity card-list an "open" action', function (): void {
    actingAsDashboardAdmin();

    $sections = dashboardStructureNodesOfKind('section');

    $pagesCard = collect($sections)->firstWhere('title', __('tbtop-cms::dashboard.recent.pages_title'));
    $submissionsCard = collect($sections)->firstWhere('title', __('tbtop-cms::dashboard.recent.submissions_title'));

    expect($pagesCard['action']['url'])->toBe(route('tbtop.cms.pages-page'))
        ->and($submissionsCard['action']['url'])->toBe(route('tbtop.cms.form-submissions-page'));
});
