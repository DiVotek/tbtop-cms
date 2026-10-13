<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Mail\CmsMailer;
use Tbtop\Cms\Mail\Recipients;
use Tbtop\Cms\Mail\TestMail;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Telegram\ChatIds;
use Tbtop\Cms\Telegram\TelegramApiException;
use Tbtop\Cms\Telegram\TelegramClient;
use Tbtop\Cms\Telegram\TelegramInvites;
use Throwable;

/**
 * The notification settings page's header actions: a test email, a test
 * Telegram message, and the Telegram invite flow (create a deep link, then
 * claim the chat that used it into `notifications.telegram.chat_ids`).
 */
final class NotificationTestActions
{
    /** @return list<ActionBuilder> */
    public static function all(S $s): array
    {
        return [
            self::sendTestEmailAction($s),
            self::sendTestTelegramMessageAction($s),
            self::createTelegramInviteAction($s),
            self::checkTelegramInviteAction($s),
        ];
    }

    private static function sendTestEmailAction(S $s): ActionBuilder
    {
        return $s->action('sendTestEmail')->label(__('tbtop-cms::settings.notifications.actions.send_test_email'))
            ->handle(self::sendTestEmail(...));
    }

    private static function sendTestEmail(ActionCtx $ctx): Effects
    {
        $settings = app(Settings::class);
        $recipients = Recipients::parse($settings->get('notifications.mail.recipients'));

        if ($recipients === []) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.test_email_no_recipients'), 'error');
        }

        try {
            app(CmsMailer::class)->send(new TestMail, $recipients);
        } catch (Throwable $e) {
            return Effects::make()->notify(
                __('tbtop-cms::settings.notifications.actions.test_email_failed', ['error' => $e->getMessage()]),
                'error',
            );
        }

        return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.test_email_sent'));
    }

    private static function sendTestTelegramMessageAction(S $s): ActionBuilder
    {
        return $s->action('sendTestTelegramMessage')->label(__('tbtop-cms::settings.notifications.actions.send_test_telegram'))
            ->handle(self::sendTestTelegramMessage(...));
    }

    private static function sendTestTelegramMessage(ActionCtx $ctx): Effects
    {
        $settings = app(Settings::class);
        $token = self::telegramToken($settings);
        $chatIds = ChatIds::parse($settings->get('notifications.telegram.chat_ids'));

        if ($token === null) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_no_token'), 'error');
        }

        if ($chatIds === []) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_no_chat_ids'), 'error');
        }

        $client = app(TelegramClient::class);
        $errors = [];

        foreach ($chatIds as $chatId) {
            try {
                $client->sendMessage($token, $chatId, __('tbtop-cms::notifications.telegram.test_message'));
            } catch (TelegramApiException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            return Effects::make()->notify(
                __('tbtop-cms::settings.notifications.actions.test_telegram_failed', ['error' => implode(' | ', $errors)]),
                'error',
            );
        }

        return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.test_telegram_sent'));
    }

    private static function createTelegramInviteAction(S $s): ActionBuilder
    {
        return $s->action('createTelegramInvite')->label(__('tbtop-cms::settings.notifications.actions.create_telegram_invite'))
            ->handle(self::createTelegramInvite(...));
    }

    private static function createTelegramInvite(ActionCtx $ctx): Effects
    {
        $token = self::telegramToken(app(Settings::class));

        if ($token === null) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_no_token'), 'error');
        }

        try {
            $invite = app(TelegramInvites::class)->create($token);
        } catch (TelegramApiException $e) {
            return Effects::make()->notify(
                __('tbtop-cms::settings.notifications.actions.telegram_invite_failed', ['error' => $e->getMessage()]),
                'error',
            );
        }

        return Effects::make()
            ->notify(__('tbtop-cms::settings.notifications.actions.telegram_invite_created', ['link' => $invite['deepLink']]))
            ->copyToClipboard($invite['deepLink']);
    }

    private static function checkTelegramInviteAction(S $s): ActionBuilder
    {
        return $s->action('checkTelegramInvite')->label(__('tbtop-cms::settings.notifications.actions.check_telegram_invite'))
            ->handle(self::checkTelegramInvite(...));
    }

    private static function checkTelegramInvite(ActionCtx $ctx): Effects
    {
        $token = self::telegramToken(app(Settings::class));

        if ($token === null) {
            return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_no_token'), 'error');
        }

        $result = app(TelegramInvites::class)->checkCurrent($token);

        return match ($result['status']) {
            'found' => self::appendChatIdAndNotify($result['chatId'], $result['chatTitle']),
            'expired' => Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_invite_expired'), 'error'),
            'error' => Effects::make()->notify(
                __('tbtop-cms::settings.notifications.actions.telegram_invite_check_failed', ['error' => $result['error'] ?? '']),
                'error',
            ),
            default => Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_invite_pending')),
        };
    }

    private static function appendChatIdAndNotify(string $chatId, string $chatTitle): Effects
    {
        $settings = app(Settings::class);
        $updated = ChatIds::append($settings->get('notifications.telegram.chat_ids'), $chatId, $chatTitle);
        $settings->set('notifications.telegram.chat_ids', $updated);

        return Effects::make()->notify(__('tbtop-cms::settings.notifications.actions.telegram_invite_claimed', [
            'chat' => $chatTitle,
            'id' => $chatId,
        ]));
    }

    private static function telegramToken(Settings $settings): ?string
    {
        $token = $settings->get('notifications.telegram.bot_token');

        return is_string($token) && trim($token) !== '' ? $token : null;
    }
}
