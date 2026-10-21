<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Support;

use Tbtop\Cms\Form\Forms;
use Tbtop\Cms\Page\Models\Page;

/**
 * `action` + optional `honeypotField` for a frontend form. `$target` is a
 * placed block's id or a {@see Forms} name; the submit route
 * takes either in its `{block}` segment.
 */
final class FormActionProps
{
    /** @return array{action: string, honeypotField?: string} */
    public static function for(Page $page, string $target, ?string $locale = null): array
    {
        $props = ['action' => route('tbtop-cms.form.submit', [
            'locale' => $locale ?? (string) app()->getLocale(),
            'page' => $page->getKey(),
            'block' => $target,
        ])];

        $honeypotField = config('tbtop-cms.forms.honeypot_field');

        if (is_string($honeypotField) && $honeypotField !== '') {
            $props['honeypotField'] = $honeypotField;
        }

        return $props;
    }
}
