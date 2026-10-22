<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['cache.default' => 'array']);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    $this->actingAs($user, 'web');
});

// -----------------------------------------------------------------------
// Test message
// -----------------------------------------------------------------------

it('notifies an error and sends nothing when no bot token is configured', function (): void {
    Http::fake();

    $this->postJson('/admin/notification-settings/actions/sendTestTelegramMessage', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.level', 'error')
        ->assertJsonPath('effects.0.message', __('tbtop-cms::settings.notifications.actions.telegram_no_token'));

    Http::assertNothingSent();
});

it('notifies an error and sends nothing when no chat ids are configured', function (): void {
    Http::fake();
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');

    $this->postJson('/admin/notification-settings/actions/sendTestTelegramMessage', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.level', 'error')
        ->assertJsonPath('effects.0.message', __('tbtop-cms::settings.notifications.actions.telegram_no_chat_ids'));

    Http::assertNothingSent();
});

it('sends a test message to every configured chat id and notifies success', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', "111\n222");

    $this->postJson('/admin/notification-settings/actions/sendTestTelegramMessage', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.level', 'success');

    Http::assertSentCount(2);
});

it('notifies the aggregated error when a test send fails', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bot was blocked'], 403)]);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', '111');

    $this->postJson('/admin/notification-settings/actions/sendTestTelegramMessage', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.level', 'error');
});

// -----------------------------------------------------------------------
// Invite: create
// -----------------------------------------------------------------------

it('creates an invite and returns a deep link built from getMe', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'my_cms_bot']])]);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');

    $response = $this->postJson('/admin/notification-settings/actions/createTelegramInvite', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.kind', 'notify');

    $message = $response->json('effects.0.message');
    expect($message)->toContain('https://t.me/my_cms_bot?start=');
});

it('notifies an error and creates nothing when no bot token is configured', function (): void {
    Http::fake();

    $this->postJson('/admin/notification-settings/actions/createTelegramInvite', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.level', 'error')
        ->assertJsonPath('effects.0.message', __('tbtop-cms::settings.notifications.actions.telegram_no_token'));
});

it('notifies an error when the bot is unreachable while creating an invite', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);
    app(Settings::class)->set('notifications.telegram.bot_token', 'bad-token');

    $this->postJson('/admin/notification-settings/actions/createTelegramInvite', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.level', 'error');
});

// -----------------------------------------------------------------------
// Invite: check
// -----------------------------------------------------------------------

it('notifies expired when no invite has been created yet', function (): void {
    Http::fake();
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');

    $this->postJson('/admin/notification-settings/actions/checkTelegramInvite', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.level', 'error')
        ->assertJsonPath('effects.0.message', __('tbtop-cms::settings.notifications.actions.telegram_invite_expired'));
});

it('claims a pending invite from getUpdates and appends the chat id to settings', function (): void {
    // create()'s code is random, generated server-side — the getUpdates fake
    // reads it back from the cache at request time (via a closure) rather
    // than needing it known up front, and both endpoints are registered in
    // one fake() call since a second call would only add stubs on top of
    // the first rather than replacing them.
    Http::fake([
        'api.telegram.org/*getMe*' => Http::response(['ok' => true, 'result' => ['username' => 'my_cms_bot']]),
        'api.telegram.org/*getUpdates*' => function () {
            $code = Cache::get('tbtop-cms:telegram:current-invite-code');

            return Http::response(['ok' => true, 'result' => [
                [
                    'update_id' => 1,
                    'message' => [
                        'message_id' => 5,
                        'text' => "/start {$code}",
                        'chat' => ['id' => 555, 'title' => 'Ops Team', 'type' => 'group'],
                    ],
                ],
            ]]);
        },
    ]);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');

    $this->postJson('/admin/notification-settings/actions/createTelegramInvite', ['payload' => []])->assertOk();

    $this->postJson('/admin/notification-settings/actions/checkTelegramInvite', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.kind', 'notify')
        ->assertJsonPath('effects.0.message', __('tbtop-cms::settings.notifications.actions.telegram_invite_claimed', [
            'chat' => 'Ops Team',
            'id' => '555',
        ]))
        // The open settings form still holds the old chat-id list: reload the
        // page so a later Save does not write that stale list back.
        ->assertJsonPath('effects.1.kind', 'redirect')
        ->assertJsonPath('effects.1.href', route('tbtop.cms.notification-settings-page'));

    // The claimed line carries the chat's name so the admin can tell whose
    // chat each id is.
    expect(app(Settings::class)->get('notifications.telegram.chat_ids'))->toContain('555 # Ops Team');
});

it('claims a group invite sent as /start@BotName CODE', function (): void {
    Http::fake([
        'api.telegram.org/*getMe*' => Http::response(['ok' => true, 'result' => ['username' => 'my_cms_bot']]),
        'api.telegram.org/*getUpdates*' => function () {
            $code = Cache::get('tbtop-cms:telegram:current-invite-code');

            return Http::response(['ok' => true, 'result' => [
                [
                    'update_id' => 1,
                    'message' => [
                        'message_id' => 5,
                        'text' => "/start@my_cms_bot {$code}",
                        'chat' => ['id' => -100555, 'title' => 'Sales', 'type' => 'supergroup'],
                    ],
                ],
            ]]);
        },
    ]);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');

    $this->postJson('/admin/notification-settings/actions/createTelegramInvite', ['payload' => []])->assertOk();
    $this->postJson('/admin/notification-settings/actions/checkTelegramInvite', ['payload' => []])->assertOk();

    expect(app(Settings::class)->get('notifications.telegram.chat_ids'))->toContain('-100555 # Sales');
});

it('labels a claimed private chat with the person name and username', function (): void {
    Http::fake([
        'api.telegram.org/*getMe*' => Http::response(['ok' => true, 'result' => ['username' => 'my_cms_bot']]),
        'api.telegram.org/*getUpdates*' => function () {
            $code = Cache::get('tbtop-cms:telegram:current-invite-code');

            return Http::response(['ok' => true, 'result' => [
                [
                    'update_id' => 1,
                    'message' => [
                        'message_id' => 5,
                        'text' => "/start {$code}",
                        'chat' => ['id' => 777, 'type' => 'private', 'first_name' => 'Dima', 'last_name' => 'S', 'username' => 'dima'],
                    ],
                ],
            ]]);
        },
    ]);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');

    $this->postJson('/admin/notification-settings/actions/createTelegramInvite', ['payload' => []])->assertOk();
    $this->postJson('/admin/notification-settings/actions/checkTelegramInvite', ['payload' => []])->assertOk();

    expect(app(Settings::class)->get('notifications.telegram.chat_ids'))->toContain('777 # Dima S (@dima)');
});

it('notifies pending when no matching message has arrived yet', function (): void {
    Http::fake([
        'api.telegram.org/*getMe*' => Http::response(['ok' => true, 'result' => ['username' => 'my_cms_bot']]),
        'api.telegram.org/*getUpdates*' => Http::response(['ok' => true, 'result' => []]),
    ]);
    app(Settings::class)->set('notifications.telegram.bot_token', 'test-token');

    $this->postJson('/admin/notification-settings/actions/createTelegramInvite', ['payload' => []])->assertOk();

    $this->postJson('/admin/notification-settings/actions/checkTelegramInvite', ['payload' => []])
        ->assertOk()
        ->assertJsonPath('effects.0.message', __('tbtop-cms::settings.notifications.actions.telegram_invite_pending'));
});
