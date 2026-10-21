<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Channels;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Notifications\Notification as BellNotification;
use Tbtop\Admin\Notifications\NotificationAction;
use Tbtop\Cms\Notifications\Contracts\Channel;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Notifications\Rendered;
use Tbtop\Cms\Settings\Settings;

/**
 * Delivers into the admin header bell: Laravel's `database` channel via
 * tbtop/admin's Notification builder, same wire shape and table
 * (`notifications`) the panel already reads. Has no configurable settings
 * of its own — the bell has no opt-out (SPEC decision predating this
 * rework, still true here).
 */
final class BellChannel implements Channel
{
    public function key(): string
    {
        return 'bell';
    }

    public function label(): string
    {
        return __('tbtop-cms::nav.items.notification_settings');
    }

    public function format(): Format
    {
        return Format::Short;
    }

    /** @return list<Field> */
    public function settings(S $s): array
    {
        return [];
    }

    public function supports(Recipient $to): bool
    {
        return $to->notifiable !== null;
    }

    public function send(Rendered $message, Recipient $to, Settings $settings): void
    {
        $notification = BellNotification::make();

        // The Short template is Telegram HTML; the bell client renders plain text.
        $subject = self::plainText($message->subject);
        $body = self::plainText($message->body);

        if ($subject !== '') {
            $notification->title($subject)->body($body);
        } else {
            $notification->title($body);
        }

        $actionUrl = $message->data['actionUrl'] ?? null;

        if (is_string($actionUrl) && $actionUrl !== '') {
            $label = $message->data['actionLabel'] ?? null;
            $notification->actions([NotificationAction::make(is_string($label) ? $label : $actionUrl)->url($actionUrl)]);
        }

        $notification->sendToDatabase($to->notifiable);
    }

    private static function plainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
