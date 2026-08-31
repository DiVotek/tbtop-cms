<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Notifications;

use Tbtop\Cms\Admin\Support\AdminUsers;
use Tbtop\Cms\Mail\Recipients as MailRecipients;
use Tbtop\Cms\Notifications\Audience;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Telegram\ChatIds;

/**
 * Builds form.submission's Recipient list, gated by the same
 * notifications.mail.enabled / notifications.telegram.enabled flat toggles
 * NotificationSettingsPage has always written — form.submission has no
 * per-type channel list in Settings yet (that arrives with cms-4's
 * generated settings page), so mail/telegram participation is decided here
 * rather than through Notifications::send()'s generic enabledChannels()
 * gate. A recipient is simply never built for a disabled channel; the
 * registered channels' own supports() then only ever sees recipients meant
 * for them.
 *
 * SubmissionNotifier surfaces a misconfigured-but-enabled channel (mail
 * enabled with no recipients, telegram enabled with no token/chat ids) as
 * an explicit failure rather than a silent no-op — see its docblock — which
 * this class reports via the two *Misconfigured() flags rather than trying
 * to synthesize it from an empty recipient list.
 */
final readonly class FormSubmissionRecipients
{
    private function __construct(
        /** @var list<Recipient> */
        public array $recipients,
        public bool $mailMisconfigured,
        public bool $telegramMisconfigured,
    ) {}

    public static function build(Settings $settings, string $locale): self
    {
        $recipients = [];

        foreach (AdminUsers::all() as $admin) {
            $recipients[] = new Recipient(Audience::Staff, $locale, notifiable: $admin);
        }

        $mailMisconfigured = false;

        if ((bool) $settings->get('notifications.mail.enabled')) {
            $emails = MailRecipients::parse($settings->get('notifications.mail.recipients'));
            $mailMisconfigured = $emails === [];

            foreach ($emails as $email) {
                $recipients[] = new Recipient(Audience::Staff, $locale, email: $email);
            }
        }

        $telegramMisconfigured = false;

        if ((bool) $settings->get('notifications.telegram.enabled')) {
            $token = $settings->get('notifications.telegram.bot_token');
            $chatIds = ChatIds::parse($settings->get('notifications.telegram.chat_ids'));
            $telegramMisconfigured = ! is_string($token) || trim($token) === '' || $chatIds === [];

            foreach ($chatIds as $chatId) {
                $recipients[] = new Recipient(Audience::Staff, $locale, phone: $chatId);
            }
        }

        return new self($recipients, $mailMisconfigured, $telegramMisconfigured);
    }
}
