<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionRetrier;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Mail\SubmissionMail;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\RecordingFormBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('tbtop-cms.blocks.types', [ContactForm::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function contactPageForRetry(): Page
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

it('marks a failed submission handled once the failure cause is gone', function (): void {
    $page = contactPageForRetry();

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ]);

    app(SubmissionRetrier::class)->retry($submission);

    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Handled)
        ->and($submission->error)->toBeNull()
        ->and($submission->handled_at)->not->toBeNull();
});

it('keeps a submission failed with an error when the block was removed from the page', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Contact'],
        'content' => [],
    ]);

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ]);

    app(SubmissionRetrier::class)->retry($submission);

    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->not->toBeNull()
        ->and($submission->handled_at)->toBeNull();
});

it('retries a submission in its stored locale, not the current app locale', function (): void {
    Language::factory()->create([
        'locale' => 'uk',
        'is_admin_active' => true,
        'is_frontend_active' => true,
    ]);
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    RecordingFormBlock::$locale = null;
    RecordingFormBlock::$data = [];
    RecordingFormBlock::$handled = 0;

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Recorder'],
        'content' => [[
            'id' => 'rec-1',
            'kind' => 'Recording',
            'active' => true,
            'data' => ['label' => ['en' => 'EN copy', 'uk' => 'UA copy']],
        ]],
    ]);

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'rec-1',
        'kind' => 'Recording',
        'locale' => 'uk',
        'payload' => ['value' => 'hello'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ]);

    app()->setLocale('en');

    app(SubmissionRetrier::class)->retry($submission);

    expect(RecordingFormBlock::$locale)->toBe('uk')
        ->and(RecordingFormBlock::$data['label'])->toBe('UA copy');
});

it('allows only one stale retry request to claim a failed submission', function (): void {
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    RecordingFormBlock::$handled = 0;

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Recorder'],
        'content' => [['id' => 'rec-1', 'kind' => 'Recording', 'active' => true, 'data' => []]],
    ]);

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'rec-1',
        'kind' => 'Recording',
        'locale' => 'en',
        'payload' => ['value' => 'hello'],
        'status' => SubmissionStatus::Failed,
        'error' => 'previous error',
    ]);
    $concurrentRequest = FormSubmission::query()->findOrFail($submission->getKey());

    app(SubmissionRetrier::class)->retry($submission);
    app(SubmissionRetrier::class)->retry($concurrentRequest);

    expect(RecordingFormBlock::$handled)->toBe(1);
});

it('does not repeat a successful block delivery when retrying failed notifications', function (): void {
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    RecordingFormBlock::$handled = 0;

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Recorder'],
        'content' => [['id' => 'rec-1', 'kind' => 'Recording', 'active' => true, 'data' => []]],
    ]);

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'rec-1',
        'kind' => 'Recording',
        'locale' => 'en',
        'payload' => ['value' => 'hello'],
        'status' => SubmissionStatus::Failed,
        'error' => 'notification failed',
    ]);
    $submission->forceFill(['handled_at' => now()])->save();

    app(SubmissionRetrier::class)->retry($submission);

    expect(RecordingFormBlock::$handled)->toBe(0)
        ->and($submission->refresh()->status)->toBe(SubmissionStatus::Handled);
});

it('re-runs the notification fan-out on retry and can flip failed to handled via mail', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.mail.enabled', true);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');

    $page = contactPageForRetry();

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ]);

    app(SubmissionRetrier::class)->retry($submission);
    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Handled);
    Mail::assertSent(SubmissionMail::class, fn (SubmissionMail $mail): bool => $mail->hasTo('ops@example.test'));
});

it('re-runs the bell fan-out on retry, notifying every current admin', function (): void {
    $admin = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $admin->id]);
    config(['tbtop-cms.admin.model' => AdminUser::class]);

    $page = contactPageForRetry();

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ]);

    app(SubmissionRetrier::class)->retry($submission);

    expect($admin->notifications()->count())->toBe(1);
});

it('keeps the submission failed with a joined error when the fan-out still fails on retry', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'still down'], 400)]);
    app(Settings::class)->set('notifications.telegram.enabled', true);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', '111');

    $page = contactPageForRetry();

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'previous error',
    ]);

    app(SubmissionRetrier::class)->retry($submission);
    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toContain('still down');
});
