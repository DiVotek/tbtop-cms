<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Contracts;

use Tbtop\Cms\Notifications\Format;

/**
 * Merchant-editable template lookup. cms-3 ships no storage for this (no
 * template editor yet — see NullTemplateStore); cms-4 backs it with
 * `tbtop_notification_templates` and wires NotificationSettingsPage to edit it.
 */
interface TemplateStore
{
    /** The merchant's own view name/content for this type+format+locale, or null to fall back to TypeDefinition::templates(). */
    public function override(string $type, Format $format, string $locale): ?string;
}
