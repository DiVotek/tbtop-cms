<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function actingAsLifecycleAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    test()->actingAs($user, 'web');

    return $user;
}

/** @param  array<string, mixed>  $overrides */
function lifecycleSubmission(array $overrides = []): FormSubmission
{
    return FormSubmission::query()->create([...[
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test'],
        'status' => SubmissionStatus::Handled,
        'lead_status' => LeadStatus::Read,
    ], ...$overrides]);
}

// -----------------------------------------------------------------------
// Row-action lifecycle: delete (mark processed / discard now live on the
// detail page — see FormSubmissionDetailPageTest.php)
// -----------------------------------------------------------------------

it('permanently deletes a submission via the row action', function (): void {
    actingAsLifecycleAdmin();
    $submission = lifecycleSubmission();

    $this->postJson('/admin/form-submissions/actions/deleteSubmission', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    expect(FormSubmission::query()->whereKey($submission->id)->exists())->toBeFalse();
});

it('declares the row-click target as the detail page url', function (): void {
    actingAsLifecycleAdmin();
    $submission = lifecycleSubmission();

    $structure = $this->get('/admin/form-submissions', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');
    expect(json_encode($structure))->toContain('"recordUrl":true');

    // The per-row resolver output rides on the table data endpoint as
    // _recordUrl (see ColumnProjection) — the structure only carries the
    // boolean flag that a resolver exists.
    $row = $this->getJson('/admin/form-submissions/tables/formSubmissions')
        ->assertOk()->json('data.data.0');

    expect($row['_recordUrl'])->toBe(route('tbtop.cms.form-submission-page', ['submission' => $submission->id]));
});

// -----------------------------------------------------------------------
// Bulk actions
// -----------------------------------------------------------------------

it('marks multiple submissions processed via the bulk action', function (): void {
    actingAsLifecycleAdmin();
    $a = lifecycleSubmission(['lead_status' => LeadStatus::New]);
    $b = lifecycleSubmission(['lead_status' => LeadStatus::Read]);
    $untouched = lifecycleSubmission(['lead_status' => LeadStatus::New]);

    $this->postJson('/admin/form-submissions/actions/markProcessedSelected', [
        'payload' => ['selection' => [$a->id, $b->id]],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    expect($a->fresh()->lead_status)->toBe(LeadStatus::Processed)
        ->and($b->fresh()->lead_status)->toBe(LeadStatus::Processed)
        ->and($untouched->fresh()->lead_status)->toBe(LeadStatus::New);
});

it('discards multiple submissions via the bulk action', function (): void {
    actingAsLifecycleAdmin();
    $a = lifecycleSubmission();
    $b = lifecycleSubmission();

    $this->postJson('/admin/form-submissions/actions/discardSelected', [
        'payload' => ['selection' => [$a->id, $b->id]],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    expect($a->fresh()->lead_status)->toBe(LeadStatus::Discarded)
        ->and($b->fresh()->lead_status)->toBe(LeadStatus::Discarded);
});

it('deletes multiple submissions via the bulk action', function (): void {
    actingAsLifecycleAdmin();
    $a = lifecycleSubmission();
    $b = lifecycleSubmission();
    $untouched = lifecycleSubmission();

    $this->postJson('/admin/form-submissions/actions/deleteSelected', [
        'payload' => ['selection' => [$a->id, $b->id]],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    expect(FormSubmission::query()->whereKey($a->id)->exists())->toBeFalse()
        ->and(FormSubmission::query()->whereKey($b->id)->exists())->toBeFalse()
        ->and(FormSubmission::query()->whereKey($untouched->id)->exists())->toBeTrue();
});

it('notifies without touching any rows when a bulk action is invoked with an empty selection', function (): void {
    actingAsLifecycleAdmin();
    $submission = lifecycleSubmission(['lead_status' => LeadStatus::New]);

    $this->postJson('/admin/form-submissions/actions/markProcessedSelected', [
        'payload' => ['selection' => []],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::New);
});

// -----------------------------------------------------------------------
// Tabs: ?tab=<name> narrows by lead_status
// -----------------------------------------------------------------------

it('filters the table by lead_status via the tab query param', function (): void {
    actingAsLifecycleAdmin();
    lifecycleSubmission(['lead_status' => LeadStatus::New]);
    lifecycleSubmission(['lead_status' => LeadStatus::Read]);
    lifecycleSubmission(['lead_status' => LeadStatus::Processed]);
    lifecycleSubmission(['lead_status' => LeadStatus::Discarded]);

    // lead_status is a formatUsing() column — the projected value is already
    // the translated label (e.g. 'New'), not the raw enum value ('new').
    $newRows = $this->getJson('/admin/form-submissions/tables/formSubmissions?tab=new')
        ->assertOk()->json('data.data');
    expect($newRows)->toHaveCount(1)
        ->and($newRows[0]['lead_status'])->toBe(__('tbtop-cms::form-submissions.statuses.new'));

    $processedRows = $this->getJson('/admin/form-submissions/tables/formSubmissions?tab=processed')
        ->assertOk()->json('data.data');
    expect($processedRows)->toHaveCount(1)
        ->and($processedRows[0]['lead_status'])->toBe(__('tbtop-cms::form-submissions.statuses.processed'));

    $allRows = $this->getJson('/admin/form-submissions/tables/formSubmissions?tab=all')
        ->assertOk()->json('data.data');
    expect($allRows)->toHaveCount(4);
});

it('defaults to the all tab (no lead_status narrowing) when no tab param is sent', function (): void {
    actingAsLifecycleAdmin();
    lifecycleSubmission(['lead_status' => LeadStatus::New]);
    lifecycleSubmission(['lead_status' => LeadStatus::Discarded]);

    $rows = $this->getJson('/admin/form-submissions/tables/formSubmissions')
        ->assertOk()->json('data.data');

    expect($rows)->toHaveCount(2);
});

// -----------------------------------------------------------------------
// Filters: delivery status + locale
// -----------------------------------------------------------------------

it('filters the table by delivery status', function (): void {
    actingAsLifecycleAdmin();
    lifecycleSubmission(['status' => SubmissionStatus::Failed, 'error' => 'boom']);
    lifecycleSubmission(['status' => SubmissionStatus::Handled]);

    // Filtering happens server-side against the RAW column; with no delivery
    // column formatting the projection anymore, status also comes back raw
    // (which is what the retry action's hiddenIf compares against).
    $rows = $this->getJson('/admin/form-submissions/tables/formSubmissions?filters[status]=failed')
        ->assertOk()->json('data.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('failed');
});

it('filters the table by locale', function (): void {
    actingAsLifecycleAdmin();
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true, 'is_frontend_active' => true]);
    lifecycleSubmission(['locale' => 'en']);
    $uk = lifecycleSubmission(['locale' => 'uk']);

    $rows = $this->getJson('/admin/form-submissions/tables/formSubmissions?filters[locale]=uk')
        ->assertOk()->json('data.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['_key'])->toBe($uk->id);
});

it('declares delivery and locale as filter fields in the table structure', function (): void {
    actingAsLifecycleAdmin();
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true, 'is_frontend_active' => true]);

    $structure = $this->get('/admin/form-submissions', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $json = json_encode($structure);
    expect($json)->toContain('"filters"')
        ->toContain('"status"')
        ->toContain('"locale"');
});

// -----------------------------------------------------------------------
// Lead column (5.4)
// -----------------------------------------------------------------------

it('renders "Name · email" in the Lead column when both are present', function (): void {
    actingAsLifecycleAdmin();
    lifecycleSubmission(['payload' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.test']]);

    $row = $this->getJson('/admin/form-submissions/tables/formSubmissions')
        ->assertOk()->json('data.data.0');

    expect($row['payload'])->toBe('Ada Lovelace · ada@example.test');
});

it('renders just the name in the Lead column when email is absent', function (): void {
    actingAsLifecycleAdmin();
    lifecycleSubmission(['payload' => ['name' => 'Ada Lovelace']]);

    $row = $this->getJson('/admin/form-submissions/tables/formSubmissions')
        ->assertOk()->json('data.data.0');

    expect($row['payload'])->toBe('Ada Lovelace');
});

it('falls back to the first non-empty scalar payload value in the Lead column', function (): void {
    actingAsLifecycleAdmin();
    lifecycleSubmission(['payload' => ['subject' => 'Partnership inquiry', 'notes' => 'urgent']]);

    $row = $this->getJson('/admin/form-submissions/tables/formSubmissions')
        ->assertOk()->json('data.data.0');

    expect($row['payload'])->toBe('Partnership inquiry');
})->skip(runsOnMySql(), 'tbtop-cms-next-bugs: Lead column follows MySQL JSON key order');

it('renders an em dash in the Lead column when the payload has no usable scalar value', function (): void {
    actingAsLifecycleAdmin();
    lifecycleSubmission(['payload' => []]);

    $row = $this->getJson('/admin/form-submissions/tables/formSubmissions')
        ->assertOk()->json('data.data.0');

    expect($row['payload'])->toBe(__('tbtop-cms::form-submissions.values.lead_unknown'));
});

// -----------------------------------------------------------------------
// Dashboard recent-submissions lead identity
// -----------------------------------------------------------------------

it('carries the lead identity as the title and the form kind in meta on dashboard recent-submission rows', function (): void {
    actingAsLifecycleAdmin();
    lifecycleSubmission(['kind' => 'ContactForm', 'payload' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.test']]);

    $structure = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    // Mirrors DashboardPageTest's own collectDashboardNodesByKind: pushes
    // $node['options'], not the raw node — 'items' lives under options.
    $lists = [];
    $collect = function (array $node) use (&$collect, &$lists): void {
        if (($node['kind'] ?? null) === 'list') {
            $lists[] = $node['options'] ?? [];
        }
        foreach ((array) ($node['options']['children'] ?? []) as $child) {
            if (is_array($child)) {
                $collect($child);
            }
        }
    };
    $collect($structure);

    $submissionsList = collect($lists)->first(
        fn (array $list): bool => collect($list['items'] ?? [])->contains(
            fn (array $item): bool => ($item['title'] ?? null) === 'Ada Lovelace · ada@example.test',
        ),
    );

    expect($submissionsList)->not->toBeNull();

    $item = collect($submissionsList['items'])->firstWhere('title', 'Ada Lovelace · ada@example.test');
    expect($item['meta'])->toContain('ContactForm');
});
