<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health;

/**
 * A check's outcome. Message/hint are translation KEYS, not rendered
 * strings: the console command renders in the app locale, the admin page
 * renders per-request locale, and rendering here would freeze both to
 * whatever locale the (possibly queued/scheduled) check happened to run in.
 * $replace feeds __($key, $replace) at render time for dynamic values
 * (an age in minutes, a disk name) without the check needing to know how
 * its consumer will localize them.
 */
final class HealthCheckResult
{
    /**
     * @param  array<string, string|int|float>  $messageReplace
     * @param  array<string, string|int|float>  $hintReplace
     */
    public function __construct(
        public readonly string $checkId,
        public readonly HealthStatus $status,
        public readonly string $labelKey,
        public readonly string $messageKey,
        public readonly array $messageReplace = [],
        public readonly ?string $hintKey = null,
        public readonly array $hintReplace = [],
    ) {}

    public function label(): string
    {
        return __($this->labelKey);
    }

    public function message(): string
    {
        return __($this->messageKey, $this->messageReplace);
    }

    public function hint(): ?string
    {
        return $this->hintKey === null ? null : __($this->hintKey, $this->hintReplace);
    }
}
