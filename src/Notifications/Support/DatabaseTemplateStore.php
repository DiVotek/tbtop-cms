<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Support;

use Tbtop\Cms\Notifications\Contracts\TemplateStore;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Models\NotificationTemplate;

/** Merchant-edited overrides stored in tbtop_notification_templates; locale lookup is strict, no fallback. */
final class DatabaseTemplateStore implements TemplateStore
{
    public function override(string $type, Format $format, string $locale): ?string
    {
        $row = NotificationTemplate::query()
            ->where('type', $type)
            ->where('format', $format->value)
            ->where('locale', $locale)
            ->first();

        if ($row === null) {
            return null;
        }

        return self::compose($row->subject, $row->body);
    }

    /** Shared wire format between this store, TemplateProbe and the admin editor: a subject section prepended to the body. */
    public static function compose(string $subject, string $body): string
    {
        return "@section('subject')" . $subject . "@endsection\n" . $body;
    }
}
