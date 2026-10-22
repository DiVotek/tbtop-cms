<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

/**
 * Optional companion to {@see HandlesFormSubmission}: names the submitted
 * fields for staff. Staff notifications list each payload field under its
 * label in the reader's admin UI locale; a field the map leaves out (or a
 * form without this interface) shows its raw payload key.
 */
interface HasFieldLabels
{
    /**
     * Field labels keyed by payload field name, in `$locale` (an admin UI
     * locale, not the page's).
     *
     * @return array<string, string>
     */
    public function fieldLabels(string $locale): array;
}
