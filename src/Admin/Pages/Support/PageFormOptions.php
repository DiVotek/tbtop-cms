<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\PageTypeRegistry;

class PageFormOptions
{
    /**
     * Legacy sentinel for "no parent", from when parent_id was a Radix
     * Select (which forbids an empty-string item value). The field is now an
     * async relation picker that clears to a real null, but PageForm::save()
     * still tolerates this string as a regression guard — see its
     * nullableInt().
     */
    public const NO_PARENT = 'none';

    /** @return list<array{value: string, label: string}> */
    public static function types(): array
    {
        $options = [];

        foreach (app(PageTypeRegistry::class)->all() as $type) {
            $options[] = ['value' => $type->key(), 'label' => self::typeLabel($type->key(), $type->label())];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    public static function statuses(): array
    {
        return array_map(
            static fn (PageStatus $status): array => [
                'value' => $status->value,
                'label' => __('tbtop-cms::pages.status.' . $status->value),
            ],
            PageStatus::cases(),
        );
    }

    /**
     * Registry labels aren't guaranteed to be translated; fall back through
     * pages.types.* (already used for the list's type column) before the
     * raw registry label.
     *
     * Public alias below: the SEO settings page labels its per-type template
     * fields by the same rule, and a second copy of this fallback would drift.
     */
    public static function typeLabelFor(string $key, string $fallback): string
    {
        return self::typeLabel($key, $fallback);
    }

    private static function typeLabel(string $key, string $fallback): string
    {
        $translationKey = 'tbtop-cms::pages.types.' . $key;
        $label = __($translationKey);

        return $label === $translationKey ? $fallback : $label;
    }
}
