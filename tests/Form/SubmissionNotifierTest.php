<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Mail\SubmissionMail;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\GatedContractAdminUser;
use Tbtop\Cms\Tests\Fixtures\ThrowingFormBlock;

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
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);

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

// -----------------------------------------------------------------------
// Enabled-but-misconfigured channels fail honestly (never silent success)
// -----------------------------------------------------------------------

it('marks the submission failed when mail is enabled but no recipients are configured', function (): void {
    Mail::fake();
    notifierAdmin();
    app(Settings::class)->set('notifications.mail.enabled', true);
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
    app(Settings::class)->set('notifications.telegram.enabled', true);
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

// -----------------------------------------------------------------------
// Mail
// -----------------------------------------------------------------------

it('mails every configured recipient when enabled', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.mail.enabled', true);
    app(Settings::class)->set('notifications.mail.recipients', "ops@example.test\nsales@example.test");
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    Mail::assertSent(
        SubmissionMail::class,
        fn (SubmissionMail $mail): bool => $mail->hasTo('ops@example.test') && $mail->hasTo('sales@example.test'),
    );
});

it('sends no mail when disabled even with recipients configured', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.mail.enabled', false);
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
    app(Settings::class)->set('notifications.telegram.enabled', true);
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
    app(Settings::class)->set('notifications.telegram.enabled', true);
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

it('sends nothing over telegram when disabled', function (): void {
    Http::fake();
    app(Settings::class)->set('notifications.telegram.enabled', false);
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
    app(Settings::class)->set('notifications.mail.enabled', true);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    app(Settings::class)->set('notifications.telegram.enabled', true);
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
    app(Settings::class)->set('notifications.mail.enabled', true);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
    $page = notifierContactPage();

    $this->post(notifierSubmitUrl($page), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi',
    ])->assertRedirect();

    Mail::assertNothingSent();
    expect(FormSubmission::query()->count())->toBe(0);
});
