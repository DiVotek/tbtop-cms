<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Notifications;

use Tbtop\Cms\Admin\Support\AdminLocales;
use Tbtop\Cms\Admin\Support\AdminUsers;
use Tbtop\Cms\Mail\Recipients as MailRecipients;
use Tbtop\Cms\Notifications\Audience;
use Tbtop\Cms\Notifications\Notifications;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Telegram\ChatIds;

/**
 * Builds form.submission's Recipient list, gated by
 * Notifications::enabledChannels('form.submission') — the same per-type
 * channel list (notifications.form.submission.channels in Settings, else
 * the type's registered default) every other notification type resolves
 * through. A recipient is simply never built for a channel absent from
 * that list; the registered channels' own supports() then only ever sees
 * recipients meant for them.
 *
 * SubmissionNotifier surfaces a misconfigured-but-enabled channel (mail
 * enabled with no recipients, telegram enabled with no token/chat ids) as
 * an explicit failure rather than a silent no-op — see its docblock — which
 * this class reports via the two *Misconfigured() flags rather than trying
 * to synthesize it from an empty recipient list.
 *
 * Every recipient is staff, so its locale is an admin UI locale, never the
 * submission's page locale: a bell admin's own profile locale, and the
 * panel's default UI locale for a mail address or a Telegram chat, which
 * belong to no admin.
 */
final readonly class FormSubmissionRecipients
{
    private function __construct(
        /** @var list<Recipient> */
        public array $recipients,
        public bool $mailMisconfigured,
        public bool $telegramMisconfigured,
    ) {}

    public static function build(Settings $settings): self
    {
        $enabled = Notifications::enabledChannels('form.submission');
        $locale = AdminLocales::default();
        $recipients = [];

        if (in_array('bell', $enabled, true)) {
            $admins = AdminUsers::all();
            $locales = AdminLocales::forAdmins($admins);

            foreach ($admins as $admin) {
                $recipients[] = new Recipient(Audience::Staff, $locales[$admin->getKey()], notifiable: $admin);
            }
        }

        $mailMisconfigured = false;

        if (in_array('mail', $enabled, true)) {
            $emails = MailRecipients::parse($settings->get('notifications.mail.recipients'));
            $mailMisconfigured = $emails === [];

            foreach ($emails as $email) {
                $recipients[] = new Recipient(Audience::Staff, $locale, email: $email);
            }
        }

        $telegramMisconfigured = false;

        if (in_array('telegram', $enabled, true)) {
            $token = $settings->get('notifications.telegram.bot_token');
            $chatIds = ChatIds::parse($settings->get('notifications.telegram.chat_ids'));
            $telegramMisconfigured = ! is_string($token) || trim($token) === '' || $chatIds === [];

            // Without a token every chat send would fail with the same error
            // the misconfigured flag already reports once.
            foreach ($telegramMisconfigured ? [] : $chatIds as $chatId) {
                $recipients[] = new Recipient(Audience::Staff, $locale, phone: $chatId);
            }
        }

        return new self($recipients, $mailMisconfigured, $telegramMisconfigured);
    }
}
