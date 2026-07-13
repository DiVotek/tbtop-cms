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
        return __('tbtop-cms::settings.notifications.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::settings.notifications.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => $this->title()],
        ];
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
            $s->boolean('notification_mail_enabled')->label(__('tbtop-cms::settings.notifications.fields.mail_enabled')),
            $s->textarea('notification_mail_recipients')->label(__('tbtop-cms::settings.notifications.fields.recipients'))
                ->rules('nullable')->helperText(__('tbtop-cms::settings.notifications.helpers.recipients')),
            $s->password('notification_mail_smtp_password')->label(__('tbtop-cms::settings.notifications.fields.smtp_password'))
                ->rules('nullable|max:255')->helperText(__('tbtop-cms::settings.notifications.helpers.smtp_password')),
        ];
    }
}
