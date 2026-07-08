<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;

class NotificationSettingsPage extends SettingsPageBase
{
    public static function path(): string
    {
        return 'notification-settings';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'settings',
            'label' => __('tbtop-cms::nav.items.notification_settings'),
            'order' => 40,
        ];
    }

    #[\Override]
    public function title(): string
    {
        return 'Notification settings';
    }

    /** @return array<string, string> */
    protected function map(): array
    {
        return [
            'notification_mail_enabled' => 'notifications.mail.enabled',
            'notification_mail_recipients' => 'notifications.mail.recipients',
            'notification_mail_smtp_password' => 'notifications.mail.smtp_password',
        ];
    }

    /** @return list<Field> */
    protected function fields(S $s): array
    {
        return [
            $s->boolean('notification_mail_enabled')->label('Mail enabled'),
            $s->textarea('notification_mail_recipients')->label('Recipients')
                ->rules('nullable')->helperText('One email address per line.'),
            $s->password('notification_mail_smtp_password')->label('SMTP password')
                ->rules('nullable|max:255')->helperText('Leave blank to keep the current value.'),
        ];
    }
}
