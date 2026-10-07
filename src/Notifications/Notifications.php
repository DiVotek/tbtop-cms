<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use InvalidArgumentException;
use Tbtop\Cms\Notifications\Contracts\Channel;
use Tbtop\Cms\Notifications\Contracts\TemplateStore;
use Tbtop\Cms\Settings\Settings;
use Throwable;

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
 *
 * A template that fails to render is that pair's failure too (a Failed
 * Delivery with stage Render, its log row and NotificationFailed), never an
 * exception out of send().
 *
 * That outcome is the return value: exactly the deliveries this call made.
 * Callers must not reconstruct it by re-reading notification_log — the log
 * is shared by every concurrent request and carries no correlation to the
 * entity that triggered a send, so a window query there attributes another
 * caller's failure to this one.
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

    /**
     * @param  list<Recipient>  $recipients
     * @return list<Delivery> one entry per attempted (recipient, channel) pair, in dispatch order
     */
    public static function send(string $typeKey, object $payload, array $recipients): array
    {
        $registry = app(NotificationRegistry::class);
        $type = $registry->type($typeKey);
        $payloadClass = $type->payloadClass();

        if (! $payload instanceof $payloadClass) {
            throw new InvalidArgumentException(
                "Notification [{$typeKey}] expects a payload of type [{$payloadClass}], got [" . $payload::class . '].',
            );
        }

        $renderer = app(TemplateRenderer::class);
        $templateStore = app(TemplateStore::class);
        $channelKeys = self::enabledChannels($typeKey);

        $recorder = app(DeliveryRecorder::class);
        $recorder->begin();

        try {
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

                    $format = $channel->format();
                    $override = $templateStore->override($typeKey, $format, $recipient->locale);

                    // One pair's template failing is that pair's failure, not
                    // the whole send's: it is logged and recorded like a
                    // channel that threw, and the remaining pairs still run.
                    try {
                        $rendered = $override !== null
                            ? self::renderWithFallback($renderer, $override, $type, $format, $payload, $recipient, $typeKey)
                            : self::renderBuiltIn($renderer, $type, $format, $payload, $recipient);
                    } catch (Throwable $e) {
                        Log::error('notifications.template_render_failed', [
                            'type' => $typeKey,
                            'channel' => $channelKey,
                            'format' => $format->value,
                            'locale' => $recipient->locale,
                            'error' => $e->getMessage(),
                        ]);

                        app(NotificationChannelDriver::class)->recordFailure($typeKey, $channelKey, $recipient, $e->getMessage(), DeliveryStage::Render);

                        continue;
                    }

                    if ($rendered === null) {
                        continue;
                    }

                    $notifiable = $recipient->notifiable ?? new AnonymousNotifiable;

                    NotificationFacade::sendNow($notifiable, new TemplatedNotification($typeKey, $channelKey, $rendered, $recipient));
                }
            }
        } finally {
            $deliveries = $recorder->end();
        }

        return $deliveries;
    }

    /** @return list<string> */
    public static function enabledChannels(string $typeKey): array
    {
        $type = app(NotificationRegistry::class)->type($typeKey);
        $configured = app(Settings::class)->get("notifications.{$typeKey}.channels");

        if (! is_array($configured)) {
            return $type->defaultChannelValues();
        }

        /** @var list<string> */
        return array_values(array_filter($configured, is_string(...)));
    }

    /**
     * Renders the merchant's stored override; a failure falls back to the
     * built-in view (logged as a warning) rather than dropping the channel.
     * A built-in view that throws as well propagates to send(), which records
     * the pair as a render failure.
     */
    private static function renderWithFallback(
        TemplateRenderer $renderer,
        string $override,
        TypeDefinition $type,
        Format $format,
        object $payload,
        Recipient $recipient,
        string $typeKey,
    ): ?Rendered {
        try {
            return $renderer->render($override, $payload, $recipient->locale);
        } catch (Throwable $e) {
            Log::warning('notifications.template_override_failed', [
                'type' => $typeKey,
                'format' => $format->value,
                'locale' => $recipient->locale,
                'error' => $e->getMessage(),
            ]);

            return self::renderBuiltIn($renderer, $type, $format, $payload, $recipient);
        }
    }

    private static function renderBuiltIn(TemplateRenderer $renderer, TypeDefinition $type, Format $format, object $payload, Recipient $recipient): ?Rendered
    {
        $view = $type->templateFor($format);

        if ($view === null) {
            return null;
        }

        return $renderer->render($view, $payload, $recipient->locale);
    }
}
