<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
    CmsUserProfile::create(['user_id' => $user->id]);
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

it('shows both the lead status and the mail delivery status columns', function (): void {
    actingAsCmsAdmin();

    $structure = $this->get('/admin/form-submissions', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    expect(json_encode($structure))
        ->toContain('"lead_status"')
        ->toContain('"status"');
});

it('loads the payload for the view action', function (): void {
    actingAsCmsAdmin();
    $page = contactPageForListing();

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Pending,
    ]);

    $this->postJson('/admin/form-submissions/actions/viewPayload/data', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk()
        ->assertJsonPath('data.payload.name', 'Ada')
        ->assertJsonPath('data.payload.email', 'ada@example.test');
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

it('omits the inbox badge when nothing is unread', function (): void {
    actingAsCmsAdmin();
    submissionWithLead(LeadStatus::Read);

    expect(inboxActionOptions())->not->toHaveKey('badge');
});

it('marks a new submission read when its payload is opened', function (): void {
    actingAsCmsAdmin();
    $submission = submissionWithLead(LeadStatus::New);

    $this->postJson('/admin/form-submissions/actions/viewPayload/data', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk();

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::Read);
});

it('leaves an already-read submission unchanged when reopened', function (): void {
    actingAsCmsAdmin();
    $submission = submissionWithLead(LeadStatus::Processed);

    $this->postJson('/admin/form-submissions/actions/viewPayload/data', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk();

    expect($submission->fresh()->lead_status)->toBe(LeadStatus::Processed);
});
