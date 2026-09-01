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

    /**
     * Shared wire format between this store, TemplateProbe and the admin
     * editor: a subject section prepended to the body. The newlines around
     * $subject are load-bearing — Blade's directive tokenizer does not
     * recognize `@section('subject')text@endsection` when the directive and
     * `@endsection` are glued directly to the content with no whitespace
     * between them; it falls through to literal text and the subject is
     * lost. TemplateRenderer::render() trims the extracted section value, so
     * the padding never reaches the stored/returned subject.
     */
    public static function compose(string $subject, string $body): string
    {
        return "@section('subject')\n" . $subject . "\n@endsection\n" . $body;
    }
}
