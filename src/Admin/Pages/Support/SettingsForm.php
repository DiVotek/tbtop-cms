<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Settings\Events\SettingsSaved;
use Tbtop\Cms\Settings\Settings;

class SettingsForm
{
    /**
     * @param  array<string, string>  $map  field name => store key
     * @return array<string, mixed>
     */
    public static function record(array $map, Settings $settings): array
    {
        $record = [];

        foreach ($map as $field => $key) {
            $record[$field] = $settings->isSecret($key) ? null : $settings->get($key);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, string>  $map  field name => store key
     */
    public static function apply(array $form, array $map, Settings $settings): void
    {
        foreach ($map as $field => $key) {
            if (! array_key_exists($field, $form)) {
                continue;
            }

            $value = $form[$field];

            if ($settings->isSecret($key) && self::isBlank($value)) {
                continue;
            }

            $settings->set($key, $value);
        }

        event(new SettingsSaved(array_keys($map)));
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
