<?php

declare(strict_types=1);

namespace Tbtop\Cms\Settings\Events;

/**
 * Raised once per settings-form submission that wrote at least one value,
 * carrying the store keys actually written (e.g. `site.name`). A typical
 * subscriber invalidates a settings cache.
 */
final class SettingsSaved
{
    /** @param list<string> $keys */
    public function __construct(
        public readonly array $keys,
    ) {}
}
