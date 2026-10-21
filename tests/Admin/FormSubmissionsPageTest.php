<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config()->set('tbtop-cms.blocks.types', [ContactForm::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function actingAsCmsAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    test()->actingAs($user, 'web');

    return $user;
}

function contactPageForListing(): Page
{
    return Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Contact'],
        'content' => [[
            'id' => 'form-1',
            'kind' => 'ContactForm',
            'active' => true,
            'data' => ['recipient' => 'ops@example.test'],
        ]],
    ]);
}

it('shows the form submissions admin to an admin user', function (): void {
    actingAsCmsAdmin();

    $this->get('/admin/form-submissions', ['X-Inertia' => 'true'])->assertOk();
});

it('redirects a guest away from the form submissions admin', function (): void {
    $this->get('/admin/form-submissions')->assertRedirect(route('login'));
});

it('lists submissions newest first via the table endpoint', function (): void {
    actingAsCmsAdmin();
    $page = contactPageForListing();

    $older = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada'],
        'status' => SubmissionStatus::Pending,
        'created_at' => now()->subDay(),
    ]);
    $newer = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Bea'],
        'status' => SubmissionStatus::Handled,
    ]);

    $response = $this->getJson('/admin/form-submissions/tables/formSubmissions')->assertOk();

    $this->assertSame(2, $response->json('data.total'));
    $this->assertSame($newer->id, $response->json('data.data.0.id'));
    $this->assertSame($older->id, $response->json('data.data.1.id'));
});

it('retries a failed submission and flips it to handled', function (): void {
    actingAsCmsAdmin();
    Mail::fake();
    $page = contactPageForListing();

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ]);

    $this->postJson('/admin/form-submissions/actions/retry', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify');

    $submission->refresh();
    expect($submission->status)->toBe(SubmissionStatus::Handled)
        ->and($submission->error)->toBeNull();
});

it('keeps the submission failed when the retry cause is still present', function (): void {
    actingAsCmsAdmin();

    $submission = FormSubmission::query()->create([
        'page_id' => null,
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'Page no longer exists.',
    ]);

    $this->postJson('/admin/form-submissions/actions/retry', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()->assertJsonPath('effects.0.kind', 'notify')
        ->assertJsonPath('effects.0.level', 'error');

    $submission->refresh();
    expect($submission->status)->toBe(SubmissionStatus::Failed);
});

it('declares the retry row action hidden when the submission is not failed', function (): void {
    actingAsCmsAdmin();

    $response = $this->get('/admin/form-submissions', ['X-Inertia' => 'true'])->assertOk();

    $structure = json_decode((string) json_encode($response->json('props.structure')), true);
    expect(json_encode($structure))->toContain('"hiddenIf"')
        ->toContain('"retry"');
});

it('keeps both lead_status and delivery status in the table structure (column + filter)', function (): void {
    actingAsCmsAdmin();

    $structure = $this->get('/admin/form-submissions', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    expect(json_encode($structure))
        ->toContain('"lead_status"')
        ->toContain('"status"');
});

function submissionWithLead(LeadStatus $lead): FormSubmission
{
    return FormSubmission::query()->create([
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada'],
        'status' => SubmissionStatus::Handled,
        'lead_status' => $lead,
    ]);
}

/**
 * The Inbox action's options as the client receives them: rendered through
 * the shell and read off the serialized chrome header, not the DSL tree.
 *
 * @return array<string, mixed>
 */
function inboxActionOptions(): array
{
    $header = test()->get('/admin/form-submissions', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.tbtop.chrome.header');

    return findNodeByName($header, 'inbox')['options'] ?? [];
}

/**
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>|null
 */
function findNodeByName(array $node, string $name): ?array
{
    if (($node['name'] ?? null) === $name) {
        return $node;
    }

    foreach ($node['options']['children'] ?? [] as $child) {
        $found = findNodeByName($child, $name);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

it('shows the unread lead count on the inbox badge', function (): void {
    actingAsCmsAdmin();
    submissionWithLead(LeadStatus::New);
    submissionWithLead(LeadStatus::New);
    submissionWithLead(LeadStatus::Read);
    submissionWithLead(LeadStatus::Processed);

    expect(inboxActionOptions()['badge'])->toBe('2');
});

// CmsChrome only calls authorize('tbtop-cms.submissions') when roles are on
// (see its docblock) — an unconditional call would hide the inbox for
// everyone once tbtop-cms.roles defaults to off and the package defines no
// gates of its own.
it('shows the Inbox header item to anyone with admin access when roles are off', function (): void {
    config(['tbtop-cms.roles' => false]);
    actingAsCmsAdmin();

    $header = test()->get('/admin/pages', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.tbtop.chrome.header');

    expect(findNodeByName($header, 'inbox'))->not->toBeNull();
});

it('hides the Inbox header item from a user who lacks tbtop-cms.submissions when roles are on', function (): void {
    config(['tbtop-cms.roles' => true]);
    Gate::define('tbtop-cms.pages', static fn (): bool => true);
    Gate::define('tbtop-cms.submissions', static fn (): bool => false);
    actingAsCmsAdmin();

    $header = test()->get('/admin/pages', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.tbtop.chrome.header');

    expect(findNodeByName($header, 'inbox'))->toBeNull();
});

it('shows the Inbox header item to a user who has tbtop-cms.submissions when roles are on', function (): void {
    config(['tbtop-cms.roles' => true]);
    Gate::define('tbtop-cms.pages', static fn (): bool => true);
    Gate::define('tbtop-cms.submissions', static fn (): bool => true);
    actingAsCmsAdmin();

    $header = test()->get('/admin/pages', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.tbtop.chrome.header');

    expect(findNodeByName($header, 'inbox'))->not->toBeNull();
});

it('omits the inbox badge when nothing is unread', function (): void {
    actingAsCmsAdmin();
    submissionWithLead(LeadStatus::Read);

    expect(inboxActionOptions())->not->toHaveKey('badge');
});

// Detail-page equivalents (open marks New -> Read, other statuses untouched)
// live in FormSubmissionDetailPageTest.php now that payload viewing moved
// off this list page's removed viewPayload modal.

it('skips a retry and its audit entry for a submission that is not failed', function (SubmissionStatus $status): void {
    actingAsCmsAdmin();

    $submission = FormSubmission::query()->create([
        'page_id' => contactPageForListing()->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => $status,
    ]);

    $this->postJson('/admin/form-submissions/actions/retry', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()
        ->assertJsonPath('effects.0.kind', 'notify')
        ->assertJsonPath('effects.0.level', 'info')
        ->assertJsonPath('effects.0.message', __('tbtop-cms::form-submissions.actions.already_retrying'));

    expect(Activity::query()->where('event', 'delivery_retried')->exists())->toBeFalse()
        ->and($submission->refresh()->status)->toBe($status);
})->with([
    'processing' => [SubmissionStatus::Processing],
    'handled' => [SubmissionStatus::Handled],
]);
