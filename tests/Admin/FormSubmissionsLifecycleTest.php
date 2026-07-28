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

/**
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>|null
 */
function findLifecycleActionByName(array $node, string $name): ?array
{
    if (($node['kind'] ?? null) === 'action' && ($node['name'] ?? null) === $name) {
        return $node;
    }

    $children = $node['options']['children'] ?? [];
    foreach (is_array($children) ? $children : [] as $child) {
        if (is_array($child)) {
            $found = findLifecycleActionByName($child, $name);
            if ($found !== null) {
                return $found;
            }
        }
    }

    // rowActions/bulkActions live directly under table options, not as
    // 'children' — walk those arrays too.
    foreach (['rowActions', 'bulkActions'] as $key) {
        foreach ((array) ($node['options'][$key] ?? []) as $action) {
            if (is_array($action)) {
                $found = findLifecycleActionByName($action, $name);
                if ($found !== null) {
                    return $found;
                }
            }
        }
    }

    return null;
}

// -----------------------------------------------------------------------
// Row-action lifecycle: mark processed / discard / delete
// -----------------------------------------------------------------------

it('marks a submission processed via the row action', function (): void {
    actingAsLifecycleAdmin();
    $submission = lifecycleSubmission(['lead_status' => LeadStatus::New]);

    $this->postJson('/admin/form-submissions/actions/markProcessed', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::Processed);
});

it('discards a submission via the row action', function (): void {
    actingAsLifecycleAdmin();
    $submission = lifecycleSubmission(['lead_status' => LeadStatus::Read]);

    $this->postJson('/admin/form-submissions/actions/discard', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::Discarded);
});

it('permanently deletes a submission via the row action', function (): void {
    actingAsLifecycleAdmin();
    $submission = lifecycleSubmission();

    $this->postJson('/admin/form-submissions/actions/deleteSubmission', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    expect(FormSubmission::query()->whereKey($submission->id)->exists())->toBeFalse();
});

it('hides mark-processed and discard when already processed, and hides discard when already discarded', function (): void {
    actingAsLifecycleAdmin();

    $structure = $this->get('/admin/form-submissions', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $markProcessed = findLifecycleActionByName($structure, 'markProcessed');
    $discard = findLifecycleActionByName($structure, 'discard');

    // hiddenIf serializes under the node's 'meta' key (not 'options'), and
    // compares against the PROJECTED (translated) lead_status label, not the
    // raw enum — same quirk the pre-existing retry action documents.
    expect($markProcessed['meta']['hiddenIf'] ?? null)->not->toBeNull()
        ->and($markProcessed['meta']['hiddenIf']['op'])->toBe('in')
        ->and($markProcessed['meta']['hiddenIf']['value'])->toBe([
            __('tbtop-cms::form-submissions.statuses.processed'),
            __('tbtop-cms::form-submissions.statuses.discarded'),
        ]);

    expect($discard['meta']['hiddenIf'] ?? null)->not->toBeNull()
        ->and($discard['meta']['hiddenIf']['op'])->toBe('eq')
        ->and($discard['meta']['hiddenIf']['value'])->toBe(__('tbtop-cms::form-submissions.statuses.discarded'));
});

it('never offers a reopen action anywhere on the page', function (): void {
    actingAsLifecycleAdmin();

    $structure = $this->get('/admin/form-submissions', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    expect(json_encode($structure))->not->toContain('reopen')->not->toContain('Reopen');
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

    // Filtering happens server-side against the RAW column (TableFilterApplier
    // applies WHERE status = 'failed' before projection/formatUsing runs),
    // but the row's own 'status' value comes back projected/translated —
    // same formatUsing() quirk as lead_status above.
    $rows = $this->getJson('/admin/form-submissions/tables/formSubmissions?filters[status]=failed')
        ->assertOk()->json('data.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe(__('tbtop-cms::form-submissions.delivery.failed'));
});

it('filters the table by locale', function (): void {
    actingAsLifecycleAdmin();
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true, 'is_frontend_active' => true]);
    lifecycleSubmission(['locale' => 'en']);
    lifecycleSubmission(['locale' => 'uk']);

    $rows = $this->getJson('/admin/form-submissions/tables/formSubmissions?filters[locale]=uk')
        ->assertOk()->json('data.data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['locale'])->toBe('uk');
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
});

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

// -----------------------------------------------------------------------
// Read-only view modal (5.3)
// -----------------------------------------------------------------------

it('renders the view modal body with field-bound displayValue blocks, not an editable keyvalue field', function (): void {
    actingAsLifecycleAdmin();

    $structure = $this->get('/admin/form-submissions', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $viewAction = findLifecycleActionByName($structure, 'viewPayload');
    expect($viewAction)->not->toBeNull();

    $body = $viewAction['options']['spec']['body'] ?? null;
    expect($body)->not->toBeNull();

    $json = json_encode($body);
    expect($json)->toContain('"displayValue"')
        ->toContain('"payloadText"')
        ->not->toContain('"keyvalue"');
});

it('includes the delivery error text in the view modal data for a failed submission', function (): void {
    actingAsLifecycleAdmin();
    $submission = lifecycleSubmission(['status' => SubmissionStatus::Failed, 'error' => 'mail server unreachable']);

    $data = $this->postJson('/admin/form-submissions/actions/viewPayload/data', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()->json('data');

    expect($data['error'])->toBe('mail server unreachable')
        ->and($data['deliveryStatus'])->toBe(__('tbtop-cms::form-submissions.delivery.failed'));
});

it('shows a placeholder instead of an error string in the view modal data for a non-failed submission', function (): void {
    actingAsLifecycleAdmin();
    $submission = lifecycleSubmission(['status' => SubmissionStatus::Handled, 'error' => null]);

    $data = $this->postJson('/admin/form-submissions/actions/viewPayload/data', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()->json('data');

    expect($data['error'])->toBe(__('tbtop-cms::form-submissions.values.no_error'));
});
