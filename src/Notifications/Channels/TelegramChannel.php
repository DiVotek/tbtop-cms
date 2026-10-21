<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Channels;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Notifications\Contracts\Channel;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Notifications\Rendered;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Telegram\TelegramApiException;
use Tbtop\Cms\Telegram\TelegramClient;

/**
 * Sends one Telegram message per recipient via the Bot API, the same way
 * the pre-registry TelegramSubmissionNotifier did. A Telegram recipient has
 * neither an email nor a notifiable — chat ids are settings-level, not tied
 * to any admin user — so the chat id is carried in Recipient::$phone, the
 * only remaining generic address field the frozen Recipient shape offers;
 * it is never treated as an actual phone number. Callers building telegram
 * Recipients (see SubmissionNotifier) construct one Recipient per
 * configured chat id.
 */
final readonly class TelegramChannel implements Channel
{
    public function __construct(private TelegramClient $client) {}

    public function key(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return __('tbtop-cms::notifications.channels.telegram');
    }

    public function format(): Format
    {
        return Format::Short;
    }

    /** @return list<Field> */
    public function settings(S $s): array
    {
        return [
            $s->password('notification_telegram_bot_token')->label(__('tbtop-cms::settings.notifications.fields.telegram_bot_token'))
                ->rules('nullable|max:255')->helperText(__('tbtop-cms::settings.notifications.helpers.mail_password')),
            $s->tags('notification_telegram_chat_ids')->label(__('tbtop-cms::settings.notifications.fields.telegram_chat_ids'))
                ->rules('nullable|array')->helperText(__('tbtop-cms::settings.notifications.helpers.telegram_chat_ids')),
        ];
    }

    public function supports(Recipient $to): bool
    {
        return $to->phone !== null;
    }

    public function send(Rendered $message, Recipient $to, Settings $settings): void
    {
        $token = $settings->get('notifications.telegram.bot_token');

        if (! is_string($token) || trim($token) === '') {
            throw new TelegramApiException('Telegram notifications are enabled but the bot token is not configured.');
        }

        $this->client->sendMessage($token, (string) $to->phone, $message->body);
    }
}
