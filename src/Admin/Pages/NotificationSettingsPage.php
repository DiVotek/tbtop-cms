<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Cms\Mail\CmsMailer;
use Tbtop\Cms\Mail\Recipients;
use Tbtop\Cms\Mail\TestMail;
use Tbtop\Cms\Settings\Settings;
use Throwable;

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

    /** @return list<ActionBuilder> */
    #[\Override]
    public function headerActions(S $s): array
    {
        return [$this->sendTestEmailAction($s)];
    }

    /** @return array<string, string> */
    protected function map(): array
    {
        return [
            'notification_mail_enabled' => 'notifications.mail.enabled',
            'notification_mail_recipients' => 'notifications.mail.recipients',
            'mail_host' => 'mail.host',
            'mail_port' => 'mail.port',
            'mail_username' => 'mail.username',
            'mail_password' => 'mail.password',
            'mail_encryption' => 'mail.encryption',
            'mail_from_address' => 'mail.from_address',
            'mail_from_name' => 'mail.from_name',
        ];
    }

    /** @return list<Field|Node|TextBlock> */
    protected function fields(S $s): array
    {
        return [
            $s->displayText(__('tbtop-cms::settings.notifications.sections.notifications'))->variant('subheading'),
            $s->boolean('notification_mail_enabled')->label(__('tbtop-cms::settings.notifications.fields.mail_enabled')),
            $s->textarea('notification_mail_recipients')->label(__('tbtop-cms::settings.notifications.fields.recipients'))
                ->rules('nullable')->helperText(__('tbtop-cms::settings.notifications.helpers.recipients')),
            $s->displayDivider(),
            $s->displayText(__('tbtop-cms::settings.notifications.sections.mail_transport'))->variant('subheading'),
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

    private function sendTestEmailAction(S $s): ActionBuilder
    {
        return $s->action('sendTestEmail')->label(__('tbtop-cms::settings.notifications.actions.send_test_email'))
            ->handle($this->sendTestEmail(...));
    }

    private function sendTestEmail(ActionCtx $ctx): Effects
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
}
