<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use InvalidArgumentException;
use Tbtop\Cms\Notifications\Contracts\Channel;
use Tbtop\Cms\Notifications\Contracts\TemplateStore;
use Tbtop\Cms\Settings\Settings;

/**
 * Static façade over the container-bound NotificationRegistry — the
 * package's public surface for registering types/channels and sending. See
 * the class docblocks on NotificationRegistry, TemplatedNotification and
 * NotificationChannelDriver for how a call to send() actually reaches a
 * Channel.
 *
 * send() is synchronous: it dispatches via Notification::sendNow() and
 * blocks until every channel for every recipient has reported success or
 * failure, with the notification_log row and NotificationSent/Failed event
 * for each written before this call returns. This is deliberate, not a
 * placeholder for a future queue — a caller that needs the per-recipient,
 * per-channel outcome to decide its own state (SubmissionNotifier writing
 * FormSubmission::status, SubmissionRetrier's claim release) cannot get that
 * from a dispatch that returns before delivery happens. Asynchrony is the
 * caller's concern: run send() from inside a queued job or listener (as
 * SubmissionNotifier already defers its own call via app()->terminating())
 * when the caller doesn't need the outcome back.
 */
final class Notifications
{
    public static function type(TypeDefinition $type): void
    {
        app(NotificationRegistry::class)->registerType($type);
    }

    /** @param class-string<Channel> $channel */
    public static function channel(string $channel): void
    {
        app(NotificationRegistry::class)->registerChannel($channel);
    }

    /** @param list<Recipient> $recipients */
    public static function send(string $typeKey, object $payload, array $recipients): void
    {
        $registry = app(NotificationRegistry::class);
        $type = $registry->type($typeKey);
        $payloadClass = $type->payloadClass();

        if (! $payload instanceof $payloadClass) {
            throw new InvalidArgumentException(
                "Notification [{$typeKey}] expects a payload of type [{$payloadClass}], got [" . $payload::class . '].',
            );
        }

        $settings = app(Settings::class);
        $renderer = app(TemplateRenderer::class);
        $templateStore = app(TemplateStore::class);
        $channelKeys = self::enabledChannels($typeKey, $type, $settings);

        foreach ($recipients as $recipient) {
            if (! in_array($recipient->audience, $type->audienceValues(), true)) {
                continue;
            }

            foreach ($channelKeys as $channelKey) {
                if (! $registry->hasChannel($channelKey)) {
                    continue;
                }

                $channel = $registry->channel($channelKey);

                if (! $channel->supports($recipient)) {
                    continue;
                }

                $view = $templateStore->override($typeKey, $channel->format(), $recipient->locale)
                    ?? $type->templateFor($channel->format());

                if ($view === null) {
                    continue;
                }

                $rendered = $renderer->render($view, $payload, $recipient->locale);
                $notifiable = $recipient->notifiable ?? new AnonymousNotifiable;

                NotificationFacade::sendNow($notifiable, new TemplatedNotification($typeKey, $channelKey, $rendered, $recipient));
            }
        }
    }

    /** @return list<string> */
    private static function enabledChannels(string $typeKey, TypeDefinition $type, Settings $settings): array
    {
        $configured = $settings->get("notifications.{$typeKey}.channels");

        if (! is_array($configured)) {
            return $type->defaultChannelValues();
        }

        /** @var list<string> */
        return array_values(array_filter($configured, is_string(...)));
    }
}
