<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionRetrier;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Notifications\Channels\RenderedMail;
use Tbtop\Cms\Notifications\Events\NotificationSent;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\RecordingFormBlock;
use Tbtop\Cms\Tests\Fixtures\ThrowingFormBlock;
use Tbtop\Cms\Tests\Fixtures\ThrowingNotificationView;

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

function stuckRecordingSubmission(SubmissionStatus $status): FormSubmission
{
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    RecordingFormBlock::$handled = 0;
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Recorder'],
        'content' => [['id' => 'rec-1', 'kind' => 'Recording', 'active' => true, 'data' => []]],
    ]);

    return FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'rec-1',
        'kind' => 'Recording',
        'locale' => 'en',
        'payload' => ['value' => 'hello'],
        'status' => $status,
    ]);
}

it('retries a submission left pending or processing by a dead worker once it is stale', function (SubmissionStatus $status): void {
    $submission = stuckRecordingSubmission($status);
    $this->travel(SubmissionRetrier::STALE_AFTER_MINUTES + 1)->minutes();

    expect(SubmissionRetrier::isRetryable($submission))->toBeTrue();

    app(SubmissionRetrier::class)->retry($submission);

    expect($submission->refresh()->status)->toBe(SubmissionStatus::Handled)
        ->and(RecordingFormBlock::$handled)->toBe(1);
})->with([SubmissionStatus::Pending, SubmissionStatus::Processing]);

it('leaves a recent pending or processing submission to the send in flight', function (SubmissionStatus $status): void {
    $submission = stuckRecordingSubmission($status);
    $this->travel(SubmissionRetrier::STALE_AFTER_MINUTES - 1)->minutes();

    expect(SubmissionRetrier::isRetryable($submission))->toBeFalse();

    app(SubmissionRetrier::class)->retry($submission);

    expect($submission->refresh()->status)->toBe($status)
        ->and(RecordingFormBlock::$handled)->toBe(0);
})->with([SubmissionStatus::Pending, SubmissionStatus::Processing]);

it('lets only one of two concurrent retries claim a stale processing submission', function (): void {
    $submission = stuckRecordingSubmission(SubmissionStatus::Processing);
    $this->travel(SubmissionRetrier::STALE_AFTER_MINUTES + 1)->minutes();
    $concurrentRequest = FormSubmission::query()->findOrFail($submission->getKey());

    app(SubmissionRetrier::class)->retry($submission);
    app(SubmissionRetrier::class)->retry($concurrentRequest);

    expect(RecordingFormBlock::$handled)->toBe(1);
});

it('never retries a handled submission, however old', function (): void {
    $submission = stuckRecordingSubmission(SubmissionStatus::Handled);
    $this->travel(SubmissionRetrier::STALE_AFTER_MINUTES + 60)->minutes();

    app(SubmissionRetrier::class)->retry($submission);

    expect(SubmissionRetrier::isRetryable($submission))->toBeFalse()
        ->and(RecordingFormBlock::$handled)->toBe(0);
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
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
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
    Mail::assertSent(RenderedMail::class, fn (RenderedMail $mail): bool => $mail->hasTo('ops@example.test'));
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

it('retries a submission whose form block lives in a layout slot, not the page content', function (): void {
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    RecordingFormBlock::$handled = 0;

    // The page carries no content of its own — the block being retried
    // lives only in the header layout slot, same as a real submission
    // through it would have been located at submit time.
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Recorder'],
        'content' => [],
    ]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [['id' => 'h1', 'kind' => 'Recording', 'active' => true, 'data' => []]],
    ]);

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'h1',
        'kind' => 'Recording',
        'locale' => 'en',
        'payload' => ['value' => 'hello'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ]);

    app(SubmissionRetrier::class)->retry($submission);

    expect(RecordingFormBlock::$handled)->toBe(1)
        ->and($submission->refresh()->status)->toBe(SubmissionStatus::Handled);
});

it('keeps the submission failed with the channel error when the fan-out still fails on retry', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'still down'], 400)]);
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'telegram']);
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

/**
 * Retries through the admin row action, signed in as an admin who is also
 * the bell recipient — so "no notifications" is observable, not vacuous.
 */
function retryFromAdmin(FormSubmission $submission): AdminUser
{
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    $admin = AdminUser::create(['name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $admin->id]);

    test()->actingAs($admin, 'web')->postJson('/admin/form-submissions/actions/retry', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk();

    return $admin;
}

function failedSubmissionFor(string $kind, string $blockId): FormSubmission
{
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Form'],
        'content' => [['id' => $blockId, 'kind' => $kind, 'active' => true, 'data' => []]],
    ]);

    return FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => $blockId,
        'kind' => $kind,
        'locale' => 'en',
        'payload' => ['value' => 'hello'],
        'status' => SubmissionStatus::Failed,
        'error' => 'previous error',
    ]);
}

function enableTelegramForRetry(): void
{
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'telegram']);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', '111');
}

it('keeps a retry failed with the handler error and sends nothing when the handler throws again', function (): void {
    config()->set('tbtop-cms.blocks.types', [ThrowingFormBlock::class]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    enableTelegramForRetry();
    $submission = failedSubmissionFor('Throwing', 'throw-1');

    $admin = retryFromAdmin($submission);
    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toBe('handler boom')
        ->and($submission->handled_at)->toBeNull()
        ->and($admin->notifications()->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps a retry failed with the channel error, handler marked done, when only the fan-out fails', function (): void {
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    RecordingFormBlock::$handled = 0;
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'still down'], 400)]);
    enableTelegramForRetry();
    $submission = failedSubmissionFor('Recording', 'rec-1');

    retryFromAdmin($submission);
    $submission->refresh();

    expect(RecordingFormBlock::$handled)->toBe(1)
        ->and($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toContain('still down')
        ->and($submission->error)->not->toContain('previous error')
        ->and($submission->handled_at)->not->toBeNull();
});

it('marks a retry handled with no error when the handler and the fan-out both succeed', function (): void {
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    RecordingFormBlock::$handled = 0;
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    enableTelegramForRetry();
    $submission = failedSubmissionFor('Recording', 'rec-1');

    $admin = retryFromAdmin($submission);
    $submission->refresh();

    expect(RecordingFormBlock::$handled)->toBe(1)
        ->and($submission->status)->toBe(SubmissionStatus::Handled)
        ->and($submission->error)->toBeNull()
        ->and($submission->handled_at)->not->toBeNull()
        ->and($admin->notifications()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('shows the error toast, not a 500, and keeps the retry failed when the fan-out throws', function (): void {
    Exceptions::fake();
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    $admin = AdminUser::create(['name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $admin->id]);
    Event::listen(NotificationSent::class, fn () => throw new RuntimeException('listener boom'));
    $submission = FormSubmission::query()->create([
        'page_id' => contactPageForRetry()->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'previous error',
    ]);

    $response = $this->actingAs($admin, 'web')->postJson('/admin/form-submissions/actions/retry', [
        'payload' => ['row' => ['id' => $submission->id]],
    ])->assertOk();

    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toBe('listener boom')
        ->and(collect($response->json('effects'))->firstWhere('kind', 'notify'))
        ->toMatchArray(['message' => 'listener boom', 'level' => 'error']);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'listener boom');
});

it('keeps a retry failed with the render prefix, not the send prefix, when the template still throws', function (): void {
    Mail::fake();
    ThrowingNotificationView::html();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    $submission = FormSubmission::query()->create([
        'page_id' => contactPageForRetry()->getKey(),
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
        ->and($submission->error)->toStartWith("Couldn't render the Email notification: ")
        ->and($submission->error)->toContain('html boom')
        ->and($submission->error)->not->toContain('Mail delivery failed');
});

it('records the retry error in the admin locale when the page or block is gone', function (): void {
    app()->setLocale('uk');

    $emptyPage = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Contact'],
        'content' => [],
    ]);

    $attrs = [
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ];

    $blockGone = FormSubmission::query()->create([...$attrs, 'page_id' => $emptyPage->getKey()]);
    $pageGone = FormSubmission::query()->create([...$attrs, 'page_id' => null]);

    app(SubmissionRetrier::class)->retry($blockGone);
    app(SubmissionRetrier::class)->retry($pageGone);

    expect($blockGone->refresh()->error)->toBe(__('tbtop-cms::form-submissions.errors.block_missing'))
        ->and($blockGone->error)->not->toBe('tbtop-cms::form-submissions.errors.block_missing')
        ->and($blockGone->error)->not->toBe('Form block no longer exists on the page.')
        ->and($pageGone->refresh()->error)->toBe(__('tbtop-cms::form-submissions.errors.page_missing'))
        ->and($pageGone->error)->not->toBe('Page no longer exists.');
});
