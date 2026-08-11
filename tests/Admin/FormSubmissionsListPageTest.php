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

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    $this->actingAs($user, 'web');
});

/** @return array<string, mixed> First table node in the page structure. */
function formSubmissionsTableNode(): array
{
    $structure = test()->get('/admin/form-submissions', ['X-Inertia' => 'true'])
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

it('carries the title, subtitle, and breadcrumbs from translations', function (): void {
    $response = test()->get('/admin/form-submissions', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::form-submissions.title'))
        ->and($response->json('props.subtitle'))->toBe(__('tbtop-cms::form-submissions.subtitle'))
        ->and($response->json('props.breadcrumbs.0.label'))->toBe(__('tbtop-cms::chrome.inbox.label'));
});

it('labels every column from translations, mirroring the Pages table shape', function (): void {
    $labels = array_column(formSubmissionsTableNode()['options']['columns'] ?? [], 'label');

    // Identity -> attributes -> status -> date; delivery and locale live on
    // the detail page only.
    expect($labels)->toBe([
        __('tbtop-cms::form-submissions.columns.lead'),
        __('tbtop-cms::form-submissions.columns.form'),
        __('tbtop-cms::form-submissions.columns.page'),
        __('tbtop-cms::form-submissions.columns.status'),
        __('tbtop-cms::form-submissions.columns.submitted'),
    ]);
});

it('formats the submitted column with the translated datetime format', function (): void {
    $columns = formSubmissionsTableNode()['options']['columns'] ?? [];
    $submitted = collect($columns)->firstWhere('name', 'created_at');

    expect($submitted['format'] ?? null)->toBe(__('tbtop-cms::form-submissions.date_format'));
});

// With no delivery column formatting the projection, status reaches the
// client raw — the retry visibility condition compares against the enum
// value directly (no coupling to translations).
it('keys the retry hiddenIf on the raw failed delivery value', function (): void {
    $rowActions = formSubmissionsTableNode()['options']['rowActions'] ?? [];
    $retry = collect($rowActions)->firstWhere('name', 'retry');

    expect($retry)->not->toBeNull()
        ->and(json_encode($retry['meta']['hiddenIf'] ?? null))
        ->toContain(SubmissionStatus::Failed->value);
});

it('projects the translated lead status and a raw delivery value through the table endpoint', function (): void {
    FormSubmission::query()->create([
        'page_id' => null,
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada'],
        'status' => SubmissionStatus::Failed,
        'lead_status' => LeadStatus::New,
    ]);

    $row = $this->getJson('/admin/form-submissions/tables/formSubmissions')
        ->assertOk()
        ->json('data.data.0');

    // status has no column/formatUsing anymore, so it stays raw — that raw
    // value is what the retry action's hiddenIf keys on.
    expect($row['lead_status'])->toBe(__('tbtop-cms::form-submissions.statuses.new'))
        ->and($row['status'])->toBe(SubmissionStatus::Failed->value)
        ->and($row['page'])->toBe(__('tbtop-cms::form-submissions.values.page_deleted'));
});
