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
     * @param  array<string, mixed>  $defaults  field name => value shown while the key was never stored
     * @return array<string, mixed>
     */
    public static function record(array $map, Settings $settings, array $defaults = []): array
    {
        $record = [];

        foreach ($map as $field => $key) {
            $record[$field] = $settings->isSecret($key) ? null : $settings->get($key, $defaults[$field] ?? null);
        }

        return $record;
    }

    /** Form field carrying the "clear the saved secret" tick for a secret field. */
    public static function clearField(string $field): string
    {
        return $field . '__clear';
    }

    /** Whether a secret has a stored value. The value itself never leaves the server. */
    public static function isSecretSet(string $key): bool
    {
        return Setting::query()->where('key', $key)->exists();
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
                // Clear wins over a typed value: an explicit delete is never silently overridden.
                if ($settings->isSecret($key) && self::isTruthy($form[self::clearField($field)] ?? false)) {
                    if (self::clearSecret($key, $settings)) {
                        $applied[] = $key;
                    }

                    continue;
                }

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

    private static function isTruthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Returns false when there was nothing stored to delete. */
    private static function clearSecret(string $key, Settings $settings): bool
    {
        $before = Setting::query()->where('key', $key)->first();

        if ($before === null) {
            return false;
        }

        $settings->forget($key);
        $marker = ['changed' => true, 'is_redacted' => true];

        app(AuditContext::class)->log(
            $before,
            'deleted',
            $key,
            ['attributes' => [], 'old' => ['value' => $marker]],
        );

        return true;
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
