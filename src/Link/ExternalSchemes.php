<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

/** Messenger deep-link schemes for `external`, beyond http(s). Mirrored in resources/js/cms/link/schemes.ts. */
final class ExternalSchemes
{
    /** An allowlist, not a blocklist: `javascript:`, `data:`, `file:`, `vbscript:` must never pass. */
    public const ALLOWED = ['viber', 'tg', 'whatsapp', 'skype', 'sms'];

    /** Allowlisted scheme plus a non-empty, space-free target; `://` optional. */
    public static function matches(string $value): bool
    {
        $schemes = implode('|', array_map(
            static fn (string $scheme): string => preg_quote($scheme, '/'),
            self::ALLOWED,
        ));

        return preg_match('/^(?:' . $schemes . '):(?:\/\/)?\S+$/i', $value) === 1;
    }
}
