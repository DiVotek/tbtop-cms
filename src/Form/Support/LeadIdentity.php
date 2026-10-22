<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Support;

/**
 * A human-facing "who is this lead" label derived from a submission's raw
 * payload. Host apps author their own form blocks (SPEC_V2 §3 — any project
 * can ship a custom HandlesFormSubmission block), so the payload's keys are
 * NOT a fixed contract the way the built-in ContactForm's name/email/message
 * are — this must degrade gracefully for a payload that has neither key, one
 * of them, or nothing scalar at all.
 *
 * Shared by FormSubmissionsPage's Lead column and DashboardRecent's latest-
 * submissions card, so both surfaces read the same person the same way.
 */
final class LeadIdentity
{
    /** Roughly a table cell / card title's worth of text before it starts wrapping badly. */
    private const FALLBACK_MAX_LENGTH = 60;

    /**
     * name+email → "Name · email"; either alone → just it; neither → the
     * first non-empty scalar payload value (truncated); nothing usable at
     * all → null (callers decide their own placeholder — an em dash for a
     * table cell reads differently than a dashboard card's empty state).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function label(array $payload): ?string
    {
        $name = self::nonEmptyString($payload['name'] ?? null);
        $email = self::nonEmptyString($payload['email'] ?? null);

        if ($name !== null && $email !== null) {
            return "{$name} · {$email}";
        }

        if ($name !== null || $email !== null) {
            return $name ?? $email;
        }

        return self::firstScalarFallback($payload);
    }

    /** @param  array<string, mixed>  $payload */
    public static function name(array $payload): ?string
    {
        return self::nonEmptyString($payload['name'] ?? null);
    }

    /**
     * How to reach the lead: the email, else a phone a custom form collects.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function contact(array $payload): ?string
    {
        return self::nonEmptyString($payload['email'] ?? null) ?? self::nonEmptyString($payload['phone'] ?? null);
    }

    /**
     * Scans in payload order (whatever order the form block's fields were
     * submitted in) rather than sorting by key — a custom block's first
     * field is usually its most identifying one (a name, a subject line),
     * so this is a reasonable guess without knowing the block's shape.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function firstScalarFallback(array $payload): ?string
    {
        foreach ($payload as $value) {
            $string = self::nonEmptyString($value);
            if ($string !== null) {
                return self::truncate($string);
            }
        }

        return null;
    }

    private static function truncate(string $value): string
    {
        return mb_strlen($value) > self::FALLBACK_MAX_LENGTH
            ? mb_substr($value, 0, self::FALLBACK_MAX_LENGTH - 1) . '…'
            : $value;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
