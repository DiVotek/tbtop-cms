<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionNotifier;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Mail\SubmissionMail;
use Tbtop\Cms\Notifications\Audience;
use Tbtop\Cms\Notifications\Channels\RenderedMail;
use Tbtop\Cms\Notifications\Events\NotificationSent;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Models\NotificationLog;
use Tbtop\Cms\Notifications\Models\NotificationTemplate;
use Tbtop\Cms\Notifications\NotificationLogStatus;
use Tbtop\Cms\Notifications\Notifications;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\GatedContractAdminUser;
use Tbtop\Cms\Tests\Fixtures\ThrowingFormBlock;
use Tbtop\Cms\Tests\Fixtures\ThrowingHostChannel;
use Tbtop\Cms\Tests\Fixtures\ThrowingNotificationView;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array', 'session.driver' => 'array']);
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config()->set('tbtop-cms.blocks.types', [ContactForm::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

function notifierAdmin(string $email = 'admin@example.test'): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => $email, 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

function notifierContactPage(): Page
{
    return Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Contact'],
        'content' => [[
            'id' => 'form-1',
            'kind' => 'ContactForm',
            'active' => true,
            'data' => [],
        ]],
    ]);
}

function notifierSubmitUrl(Page $page): string
{
    return route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $page->getKey(), 'block' => 'form-1']);
}

// -----------------------------------------------------------------------
// Bell: every admin gets a database notification linking to the detail page
// -----------------------------------------------------------------------

it('sends a bell notification to every admin on a new submission', function (): void {
    $alice = notifierAdmin('alice@example.test');
    $bob = notifierAdmin('bob@example.test');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();
    $detailUrl = route('tbtop.cms.form-submission-page', ['submission' => $submission->id]);

    foreach ([$alice, $bob] as $admin) {
        $notification = $admin->notifications()->sole();
        expect($notification->data['title'])->toBe(__('tbtop-cms::notifications.bell.title'))
            ->and($notification->data['actions'][0]['url'])->toBe($detailUrl);
    }
});

it('writes the bell body as plain text', function (): void {
    $admin = notifierAdmin();
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => "Ada O'Brien", 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $data = $admin->notifications()->sole()->data;

    // The short template is Telegram HTML; the bell client renders body as text.
    expect($data['body'])->not->toContain('<b>')
        ->and($data['body'])->not->toContain('&#039;')
        ->and($data['body'])->toContain("Ada O'Brien");
});

it('does not notify a user with no admin profile row', function (): void {
    $stranger = AdminUser::create(['name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => 'secret']);
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    expect($stranger->notifications()->count())->toBe(0);
});

it('resolves bell recipients through the CmsAdmin contract when the user model implements it', function (): void {
    config(['tbtop-cms.admin.model' => GatedContractAdminUser::class]);
    $admitted = GatedContractAdminUser::create(['name' => 'Ops', 'email' => 'ops@admins.test', 'password' => 'secret']);
    $denied = GatedContractAdminUser::create(['name' => 'Guest', 'email' => 'guest@example.test', 'password' => 'secret']);
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    // Contract-admitted users are reached without any CmsUserProfile row;
    // contract-denied users are excluded by the same rule as panel access.
    expect($admitted->notifications()->count())->toBe(1)
        ->and($denied->notifications()->count())->toBe(0);
});

it('marks a submission handled from the bell channel alone when mail and telegram are both disabled', function (): void {
    notifierAdmin();
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Handled)
        ->and($submission->error)->toBeNull();
});

it('ignores the legacy mail-enabled flag when no per-type channel list is set', function (): void {
    Mail::fake();
    notifierAdmin();
    app(Settings::class)->set('notifications.mail.enabled', true);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Handled)
        ->and($submission->error)->toBeNull();
    Mail::assertNothingSent();
});

it('allows only one stale notification attempt to claim a pending submission', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    $page = notifierContactPage();
    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada'],
        'status' => SubmissionStatus::Pending,
    ]);
    $concurrentAttempt = FormSubmission::query()->findOrFail($submission->getKey());

    app(SubmissionNotifier::class)->notify($submission, $page);
    app(SubmissionNotifier::class)->notify($concurrentAttempt, $page);

    Mail::assertSentCount(1);
    expect($submission->refresh()->status)->toBe(SubmissionStatus::Handled);
});

// -----------------------------------------------------------------------
// Enabled-but-misconfigured channels fail honestly (never silent success)
// -----------------------------------------------------------------------

it('marks the submission failed when mail is enabled but no recipients are configured', function (): void {
    Mail::fake();
    notifierAdmin();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
    app(Settings::class)->set('notifications.mail.recipients', '');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toContain(__('tbtop-cms::notifications.errors.mail_no_recipients'));
    Mail::assertNothingSent();
});

it('marks the submission failed when telegram is enabled but token or chat ids are missing', function (): void {
    notifierAdmin();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'telegram']);
    app(Settings::class)->set('notifications.telegram.bot_token', 'a-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', '');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toContain(__('tbtop-cms::notifications.errors.telegram_not_configured'));
});

it('reports a missing bot token once and attempts no telegram send per chat id', function (): void {
    Http::fake();
    notifierAdmin();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'telegram']);
    app(Settings::class)->set('notifications.telegram.bot_token', '');
    app(Settings::class)->set('notifications.telegram.chat_ids', "111\n222");
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toBe(__('tbtop-cms::notifications.errors.telegram_not_configured'))
        ->and(NotificationLog::query()->where('channel', 'telegram')->count())->toBe(0);
    Http::assertNothingSent();
});

it('names a failing host channel through the generic error text', function (): void {
    Notifications::channel(ThrowingHostChannel::class);
    notifierAdmin();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'sms']);
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    expect(FormSubmission::query()->sole()->error)
        ->toBe(__('tbtop-cms::notifications.errors.channel', ['channel' => 'SMS', 'error' => 'gateway down']));
});

// -----------------------------------------------------------------------
// Mail
// -----------------------------------------------------------------------

it('mails every configured recipient when enabled', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
    app(Settings::class)->set('notifications.mail.recipients', "ops@example.test\nsales@example.test");
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    // Each configured recipient is now its own Recipient in the
    // notification registry, so delivery is one message per address
    // (RenderedMail, via MailChannel) rather than one message addressed to
    // both — see SubmissionNotifierTest deviations in the cms-3 PR.
    Mail::assertSent(RenderedMail::class, fn (RenderedMail $mail): bool => $mail->hasTo('ops@example.test'));
    Mail::assertSent(RenderedMail::class, fn (RenderedMail $mail): bool => $mail->hasTo('sales@example.test'));
});

it('mails a subject with the submitted text unescaped', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    NotificationTemplate::query()->create([
        'type' => 'form.submission',
        'format' => Format::Html,
        'locale' => 'en',
        'subject' => 'Lead: {{ $leadIdentity }}',
        'body' => '<p>{{ $leadIdentity }}</p>',
    ]);
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => "Ada O'Brien & Co", 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    // Blade escapes the subject section; a mail header is plain text.
    Mail::assertSent(RenderedMail::class, fn (RenderedMail $mail): bool => $mail->hasSubject("Lead: Ada O'Brien & Co · ada@example.test"));
});

it('stays handled when a concurrent caller writes an unrelated failed log row', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    $page = notifierContactPage();

    // A concurrent request's failed delivery, landing in the shared log
    // while this submission's own fan-out is in flight — the window a
    // watermark query over notification_log cannot exclude.
    Event::listen(NotificationSent::class, function (): void {
        NotificationLog::query()->create([
            'type' => 'form.submission',
            'channel' => 'mail',
            'audience' => Audience::Staff,
            'recipient' => 'someone-else@example.test',
            'locale' => 'en',
            'status' => NotificationLogStatus::Failed,
            'error' => 'a concurrent submission failed',
        ]);
    });

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->status)->toBe(SubmissionStatus::Handled)
        ->and($submission->error)->toBeNull();
});

it('renders structured submission values safely in mail', function (): void {
    $submission = new FormSubmission([
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => [
            'interests' => ['news', '<events>'],
            'preferences' => (object) ['daily' => true],
            'subscribed' => false,
            'note' => null,
        ],
    ]);
    $submission->forceFill(['id' => 123]);

    $html = (new SubmissionMail($submission, 'Contact'))->content()->htmlString;

    expect($html)
        ->toContain('[&quot;news&quot;,&quot;&lt;events&gt;&quot;]')
        ->toContain('{&quot;daily&quot;:true}')
        ->toContain('<strong>subscribed:</strong> </p>')
        ->toContain('<strong>note:</strong> </p>');
});

it('sends no mail when mail is not in the channel list even with recipients configured', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell']);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    Mail::assertNothingSent();
});

// -----------------------------------------------------------------------
// Telegram
// -----------------------------------------------------------------------

it('sends a telegram message per configured chat id', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'telegram']);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', "111\n222");
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
        && $request['chat_id'] === '111');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
        && $request['chat_id'] === '222');
});

it('marks the submission failed with an aggregated error when a telegram chat send fails', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'telegram']);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', '111');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toContain('chat not found');
});

it('does not persist the bot token when a telegram connection fails', function (): void {
    $token = 'SECRET-TOKEN';
    Http::fake(fn () => throw new ConnectionException("Could not connect to https://api.telegram.org/bot{$token}/sendMessage"));
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'telegram']);
    app(Settings::class)->set('notifications.telegram.bot_token', $token);
    app(Settings::class)->set('notifications.telegram.chat_ids', '111');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toContain('Could not reach Telegram.')
        ->and($submission->error)->not->toContain($token)
        ->and($submission->error)->not->toContain('api.telegram.org/bot');
});

it('sends nothing over telegram when telegram is not in the channel list', function (): void {
    Http::fake();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell']);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', '111');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    Http::assertNothingSent();
});

// -----------------------------------------------------------------------
// Combined status semantics
// -----------------------------------------------------------------------

it('marks a submission failed with a joined error when mail fails while telegram succeeds', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail', 'telegram']);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', '111');
    // No mail.host configured and no default mailer wired in the test
    // environment: CmsMailer::send() falls through to the host's default
    // mailer, which throws when nothing is configured — that's the induced
    // mail failure this test exercises, without needing to fake a specific
    // exception.
    config(['mail.default' => 'failover', 'mail.mailers.failover.mailers' => []]);
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    Http::assertSentCount(1);

    $submission = FormSubmission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->not->toBeNull();
});

it('does not run the fan-out and does not overwrite a block-level handleSubmit failure', function (): void {
    config()->set('tbtop-cms.blocks.types', [ThrowingFormBlock::class]);
    $admin = notifierAdmin();

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Boom'],
        'content' => [[
            'id' => 'throw-1',
            'kind' => 'Throwing',
            'active' => true,
            'data' => [],
        ]],
    ]);

    $this->post(route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $page->getKey(), 'block' => 'throw-1']), ['value' => 'x'])
        ->assertRedirect()
        ->assertSessionHasErrors(['form']);

    $submission = FormSubmission::query()->sole();
    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toBe('handler boom')
        ->and($admin->notifications()->count())->toBe(0);
});

it('does not fan out when storage is disabled — nothing to track delivery status against', function (): void {
    Mail::fake();
    notifierAdmin();
    config()->set('tbtop-cms.forms.store', false);
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    Mail::assertNothingSent();
    expect(FormSubmission::query()->count())->toBe(0);
});

// -----------------------------------------------------------------------
// Failures inside the fan-out never strand the claim
// -----------------------------------------------------------------------

it('marks a submission failed with the render prefix once while the other format still delivers', function (): void {
    Mail::fake();
    $admin = notifierAdmin();
    ThrowingNotificationView::html();
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
    app(Settings::class)->set('notifications.mail.recipients', "ops@example.test\nsales@example.test");
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();
    $prefix = "Couldn't render the Email notification: ";

    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toStartWith($prefix)
        ->and($submission->error)->toContain('html boom')
        // Two mail recipients, one identical error: written once.
        ->and(substr_count((string) $submission->error, $prefix))->toBe(1)
        ->and($submission->error)->not->toContain('Mail delivery failed')
        ->and($admin->notifications()->count())->toBe(1)
        ->and(NotificationLog::query()->where('channel', 'mail')->where('status', NotificationLogStatus::Failed)->count())->toBe(2);
    Mail::assertNothingSent();
});

it('marks a submission failed, not processing, when anything else in the fan-out throws', function (): void {
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported): void {
        $reported[] = [$e::class, $e->getMessage(), Context::get('submission_id')];
    });
    notifierAdmin();
    Event::listen(NotificationSent::class, fn () => throw new RuntimeException('listener boom'));
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toBe('listener boom');
    expect($reported)->toBe([[RuntimeException::class, 'listener boom', $submission->id]])
        ->and(Context::has('submission_id'))->toBeFalse();
});
