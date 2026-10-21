<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Mail\TestMail;
use Tbtop\Cms\Settings\Settings;
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

it('notifies an error and sends nothing when no recipients are configured', function (): void {
    Mail::fake();

    $this->postJson('/admin/notification-settings/actions/sendTestEmail', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.kind', 'notify')
        ->assertJsonPath('effects.0.level', 'error')
        ->assertJsonPath('effects.0.message', __('tbtop-cms::settings.notifications.actions.test_email_no_recipients'));

    Mail::assertNothingSent();
});

it('sends a test email to the configured recipients and notifies success', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.mail.recipients', "ops@example.test\nsales@example.test");

    $this->postJson('/admin/notification-settings/actions/sendTestEmail', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.kind', 'notify')
        ->assertJsonPath('effects.0.level', 'success')
        ->assertJsonPath('effects.0.message', __('tbtop-cms::settings.notifications.actions.test_email_sent'));

    Mail::assertSent(
        TestMail::class,
        fn (TestMail $mail): bool => $mail->hasTo('ops@example.test') && $mail->hasTo('sales@example.test'),
    );
});

it('writes the test email subject and body in the admin locale', function (): void {
    Mail::fake();
    app()->setLocale('uk');
    app(Settings::class)->set('site.name', 'Acme <Shop>');
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');

    $this->postJson('/admin/notification-settings/actions/sendTestEmail', ['payload' => []])->assertOk();

    Mail::assertSent(TestMail::class, function (TestMail $mail): bool {
        $subject = __('tbtop-cms::notifications.mail.test_subject', ['site' => 'Acme <Shop>']);
        $body = e(__('tbtop-cms::notifications.mail.test_body', ['site' => 'Acme <Shop>']));

        return $subject !== 'tbtop-cms::notifications.mail.test_subject'
            && $mail->envelope()->subject === $subject
            && str_contains($mail->render(), $body);
    });
});
