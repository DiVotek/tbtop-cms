<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Contracts;

use Tbtop\Cms\Notifications\Format;

/**
 * Merchant-editable template lookup, backed by
 * `tbtop_notification_templates` (see DatabaseTemplateStore). A future
 * template editor page wires into this store to let merchants edit
 * overrides.
 */
interface TemplateStore
{
    /** The merchant's own view name/content for this type+format+locale, or null to fall back to TypeDefinition::templates(). */
    public function override(string $type, Format $format, string $locale): ?string;
}
