<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Settings\Events\SettingsSaved;
use Tbtop\Cms\Settings\Models\Setting;
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
        $applied = [];

        app(AuditContext::class)->batch(function () use ($form, $map, $settings, &$applied): void {
            foreach ($map as $field => $key) {
                if (! array_key_exists($field, $form)) {
                    continue;
                }

                $value = $form[$field];

                if ($settings->isSecret($key) && self::isBlank($value)) {
                    continue;
                }

                $before = Setting::query()->where('key', $key)->first();
                $oldValue = $before !== null ? $settings->get($key) : null;
                if ($before !== null && $oldValue === $value) {
                    continue;
                }

                $settings->set($key, $value);
                $setting = Setting::query()->where('key', $key)->firstOrFail();
                $redacted = self::isRedacted($key, $settings);
                $marker = ['changed' => true, 'is_redacted' => true];

                app(AuditContext::class)->log(
                    $setting,
                    $before === null ? 'created' : 'updated',
                    $key,
                    [
                        'attributes' => ['value' => $redacted ? $marker : $value],
                        'old' => $before === null ? [] : ['value' => $redacted ? $marker : $oldValue],
                    ],
                );
                $applied[] = $key;
            }
        });

        if ($applied !== []) {
            event(new SettingsSaved($applied));
        }
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private static function isRedacted(string $key, Settings $settings): bool
    {
        return $settings->isSecret($key) || in_array($key, [
            'mail.username',
            'notifications.mail.recipients',
            'notifications.telegram.chat_ids',
        ], true);
    }
}
