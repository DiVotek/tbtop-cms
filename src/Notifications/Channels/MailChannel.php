<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Channels;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Mail\CmsMailer;
use Tbtop\Cms\Notifications\Contracts\Channel;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Notifications\Rendered;
use Tbtop\Cms\Settings\Settings;

/**
 * Sends via CmsMailer, so merchant SMTP settings (mail.host etc, same keys
 * NotificationSettingsPage has always used) apply; CmsMailer itself falls
 * back to the host's default mailer when mail.host is unset — unchanged
 * from today's behavior.
 */
final readonly class MailChannel implements Channel
{
    public function __construct(private CmsMailer $mailer) {}

    public function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return __('tbtop-cms::notifications.channels.mail');
    }

    public function format(): Format
    {
        return Format::Html;
    }

    /** @return list<Field> */
    public function settings(S $s): array
    {
        return [
            $s->text('mail_host')->label(__('tbtop-cms::settings.notifications.fields.mail_host'))
                ->rules('nullable|max:255'),
            $s->number('mail_port')->label(__('tbtop-cms::settings.notifications.fields.mail_port'))
                ->rules('nullable|integer'),
            $s->text('mail_username')->label(__('tbtop-cms::settings.notifications.fields.mail_username'))
                ->rules('nullable|max:255'),
            $s->password('mail_password')->label(__('tbtop-cms::settings.notifications.fields.mail_password'))
                ->rules('nullable|max:255')->helperText(__('tbtop-cms::settings.notifications.helpers.mail_password')),
            $s->select('mail_encryption')->label(__('tbtop-cms::settings.notifications.fields.mail_encryption'))
                ->options([
                    ['value' => 'none', 'label' => __('tbtop-cms::settings.notifications.encryption.none')],
                    ['value' => 'tls', 'label' => __('tbtop-cms::settings.notifications.encryption.tls')],
                    ['value' => 'ssl', 'label' => __('tbtop-cms::settings.notifications.encryption.ssl')],
                ])
                ->rules('nullable|in:none,tls,ssl'),
            $s->text('mail_from_address')->label(__('tbtop-cms::settings.notifications.fields.mail_from_address'))
                ->rules('nullable|email'),
            $s->text('mail_from_name')->label(__('tbtop-cms::settings.notifications.fields.mail_from_name'))
                ->rules('nullable|max:255'),
        ];
    }

    public function supports(Recipient $to): bool
    {
        return $to->email !== null;
    }

    public function send(Rendered $message, Recipient $to, Settings $settings): void
    {
        $this->mailer->send(new RenderedMail($message), (string) $to->email);
    }
}
