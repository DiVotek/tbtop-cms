<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support\Wiring;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Tbtop\Cms\Form\Events\SubmissionReceived;
use Tbtop\Cms\Form\Listeners\NotifiesAdminsOfSubmission;
use Tbtop\Cms\Form\Notifications\FormSubmissionPayload;
use Tbtop\Cms\Notifications\Audience;
use Tbtop\Cms\Notifications\Channels\BellChannel;
use Tbtop\Cms\Notifications\Channels\MailChannel;
use Tbtop\Cms\Notifications\Channels\TelegramChannel;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Notifications;
use Tbtop\Cms\Notifications\TypeDefinition;

/**
 * cms's built-in notification channels and its `form.submission` type, plus
 * the listener that fans a submission out to admins.
 *
 * Called from CmsServiceProvider::packageBooted().
 */
final class NotificationWiring
{
    public static function wire(Application $app): void
    {
        self::wireNotificationRegistry();
        self::wireSubmissionNotifications();
    }

    /**
     * Registers cms's own built-in channels and its one built-in type
     * (form.submission) with Notifications, the same way a host or another
     * package would from its own service provider — see Notifications'
     * class docblock for the registry this feeds.
     *
     * defaultChannels(['bell']) is the fallback Notifications::enabledChannels()
     * returns until a merchant sets notifications.form.submission.channels
     * via the settings page (cms-4) — so every host that has never touched
     * notification settings keeps bell-only delivery, no mail, no telegram.
     */
    private static function wireNotificationRegistry(): void
    {
        Notifications::channel(MailChannel::class);
        Notifications::channel(TelegramChannel::class);
        Notifications::channel(BellChannel::class);

        Notifications::type(
            TypeDefinition::make('form.submission')
                ->label(fn (): string => __('tbtop-cms::notifications.bell.title'))
                ->group('forms')
                ->payload(FormSubmissionPayload::class)
                ->templates([
                    Format::Html->value => 'tbtop-cms::notifications.form-submission.html',
                    Format::Short->value => 'tbtop-cms::notifications.form-submission.short',
                ])
                ->audiences([Audience::Staff])
                ->defaultChannels(['bell']),
        );
    }

    /**
     * See NotifiesAdminsOfSubmission's docblock for why the actual fan-out
     * is deferred to app()->terminating() rather than run here.
     */
    private static function wireSubmissionNotifications(): void
    {
        Event::listen(SubmissionReceived::class, NotifiesAdminsOfSubmission::class);
    }
}
