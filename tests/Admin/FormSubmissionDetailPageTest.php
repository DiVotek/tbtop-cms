<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Models\FormSubmissionComment;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function actingAsDetailAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);
    test()->actingAs($user, 'web');

    return $user;
}

/** @param  array<string, mixed>  $overrides */
function detailSubmission(array $overrides = []): FormSubmission
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
// Payload rendering + New -> Read on open
// -----------------------------------------------------------------------

it('renders the payload on the detail page', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission(['payload' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.test']]);

    $structure = $this->get("/admin/form-submissions/{$submission->id}", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $json = json_encode($structure);
    expect($json)->toContain('Ada Lovelace')->toContain('ada@example.test');
});

it('marks a new submission read when the detail page is opened', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission(['lead_status' => LeadStatus::New]);

    $this->get("/admin/form-submissions/{$submission->id}", ['X-Inertia' => 'true'])->assertOk();

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::Read);
});

it('leaves an already-processed submission unchanged when its detail page is opened', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission(['lead_status' => LeadStatus::Processed]);

    $this->get("/admin/form-submissions/{$submission->id}", ['X-Inertia' => 'true'])->assertOk();

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::Processed);
});

// -----------------------------------------------------------------------
// Free status transitions (locked "no reopen" design reversed)
// -----------------------------------------------------------------------

it('moves a discarded submission back to new via the status action', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission(['lead_status' => LeadStatus::Discarded]);

    $response = $this->postJson("/admin/form-submissions/{$submission->id}/actions/setStatusNew", [
        'payload' => [],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::New);

    // Back-to-New must redirect to the list: landing on the detail page again
    // would mark the submission Read right back (markRead on open).
    $redirects = array_values(array_filter(
        (array) $response->json('effects'),
        static fn (array $effect): bool => ($effect['kind'] ?? null) === 'redirect',
    ));
    expect($redirects)->toHaveCount(1)
        ->and($redirects[0]['href'] ?? null)->toBe(route('tbtop.cms.form-submissions-page'));
});

it('moves a processed submission back to read via the status action', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission(['lead_status' => LeadStatus::Processed]);

    $this->postJson("/admin/form-submissions/{$submission->id}/actions/setStatusRead", [
        'payload' => [],
    ])->assertOk();

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::Read);
});

it('discards a submission via the status action', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission(['lead_status' => LeadStatus::New]);

    $this->postJson("/admin/form-submissions/{$submission->id}/actions/setStatusDiscarded", [
        'payload' => [],
    ])->assertOk();

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::Discarded);
});

it('omits the button for the submission\'s current lead status', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission(['lead_status' => LeadStatus::Processed]);

    $structure = $this->get("/admin/form-submissions/{$submission->id}", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $json = (string) json_encode($structure);
    expect($json)->toContain('"setStatusNew"')
        ->toContain('"setStatusRead"')
        ->toContain('"setStatusDiscarded"')
        ->not->toContain('"setStatusProcessed"');
});

// -----------------------------------------------------------------------
// Comments
// -----------------------------------------------------------------------

it('adds a comment and renders it with author attribution', function (): void {
    $admin = actingAsDetailAdmin();
    $submission = detailSubmission();

    $this->post("/admin/form-submissions/{$submission->id}/forms/addCommentForm", [
        'body' => 'Called the lead back, following up next week.',
    ])->assertRedirect();

    expect(FormSubmissionComment::query()->where('submission_id', $submission->id)->count())->toBe(1);

    $structure = $this->get("/admin/form-submissions/{$submission->id}", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $json = (string) json_encode($structure);
    expect($json)->toContain('Called the lead back, following up next week.')
        ->toContain($admin->name);
});

it('rejects an empty comment', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission();

    $this->post("/admin/form-submissions/{$submission->id}/forms/addCommentForm", [
        'body' => '',
    ])->assertSessionHasErrors('body');

    expect(FormSubmissionComment::query()->where('submission_id', $submission->id)->count())->toBe(0);
});

it('shows the empty-comments placeholder when there are no comments', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission();

    $structure = $this->get("/admin/form-submissions/{$submission->id}", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    expect(json_encode($structure))->toContain(__('tbtop-cms::form-submissions.comments.empty'));
});

// -----------------------------------------------------------------------
// Delete
// -----------------------------------------------------------------------

it('deletes the submission from the detail page and redirects to the list', function (): void {
    actingAsDetailAdmin();
    $submission = detailSubmission();

    $this->postJson("/admin/form-submissions/{$submission->id}/actions/deleteSubmission", [
        'payload' => [],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify')
        ->assertJsonPath('effects.1.href', route('tbtop.cms.form-submissions-page'));

    expect(FormSubmission::query()->whereKey($submission->id)->exists())->toBeFalse();
});
