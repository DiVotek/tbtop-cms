<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Language\LocaleCatalog;
use Tbtop\Cms\Language\Models\Language;

class LanguageForm
{
    /** @return list<Field> */
    public static function createFields(S $s): array
    {
        return [
            $s->select('locale')->label(__('tbtop-cms::languages.fields.language'))->required()->searchable()
                ->options(self::availableLocaleOptions()),
            $s->boolean('is_default')->label(__('tbtop-cms::languages.fields.is_default')),
            $s->boolean('is_admin_active')->label(__('tbtop-cms::languages.fields.is_admin_active')),
            $s->boolean('is_frontend_active')->label(__('tbtop-cms::languages.fields.is_frontend_active')),
            $s->number('sort_order')->label(__('tbtop-cms::languages.fields.sort_order')),
        ];
    }

    /** @return list<Field> */
    public static function editFields(S $s): array
    {
        return [
            $s->text('name')->label(__('tbtop-cms::languages.fields.name'))->required(),
            $s->text('locale')->label(__('tbtop-cms::languages.fields.locale'))->required()
                ->meta('disabled', true)->helperText(__('tbtop-cms::languages.helpers.locale')),
            $s->text('og_locale')->label(__('tbtop-cms::languages.fields.og_locale'))
                ->helperText(__('tbtop-cms::languages.helpers.og_locale')),
            $s->boolean('is_default')->label(__('tbtop-cms::languages.fields.is_default')),
            $s->boolean('is_admin_active')->label(__('tbtop-cms::languages.fields.is_admin_active'))
                ->helperText(__('tbtop-cms::languages.helpers.is_admin_active')),
            $s->boolean('is_frontend_active')->label(__('tbtop-cms::languages.fields.is_frontend_active'))
                ->helperText(__('tbtop-cms::languages.helpers.is_frontend_active')),
            $s->number('sort_order')->label(__('tbtop-cms::languages.fields.sort_order')),
        ];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Language
    {
        $previousDefault = Language::query()->where('is_default', true)->first();

        if ($id !== null) {
            $language = Language::query()->findOrFail($id);
            $wasDefault = $language->is_default;
            $language->name = (string) ($form['name'] ?? $language->name);
            $language->og_locale = self::guardOgLocale($form['og_locale'] ?? $language->og_locale);
        } else {
            $wasDefault = false;
            $locale = (string) ($form['locale'] ?? '');
            $name = self::guardLocale($locale);
            // Soft-deleted rows still hold the unique locale index: re-picking
            // a deleted locale revives that row instead of inserting a duplicate.
            $language = Language::onlyTrashed()->where('locale', $locale)->first() ?? new Language;
            if ($language->trashed()) {
                $language->restore();
            }
            $language->locale = $locale;
            $language->name = $name;
        }

        $language->fill([
            'is_default' => (bool) ($form['is_default'] ?? false),
            'is_admin_active' => (bool) ($form['is_admin_active'] ?? false),
            'is_frontend_active' => (bool) ($form['is_frontend_active'] ?? false),
            'sort_order' => (int) ($form['sort_order'] ?? 0),
        ]);

        if ($language->exists && ! $wasDefault && $language->is_default) {
            app(AuditContext::class)->during(
                'default_changed',
                [
                    'previous_language_id' => $previousDefault?->getKey(),
                    'previous_locale' => $previousDefault?->locale,
                ],
                static fn (): bool => $language->save(),
            );
        } else {
            $language->save();
        }

        return $language;
    }

    /** @return list<array{value: string, label: string}> */
    private static function availableLocaleOptions(): array
    {
        $taken = Language::query()->pluck('locale')->all();

        return array_values(array_filter(
            LocaleCatalog::options(),
            static fn (array $option): bool => ! in_array($option['value'], $taken, true),
        ));
    }

    /** Validates the locale against the catalog and uniqueness, returning its native name. */
    private static function guardLocale(string $locale): string
    {
        $name = LocaleCatalog::name($locale);

        if ($name === null) {
            throw ValidationException::withMessages(['locale' => __('tbtop-cms::languages.validation.locale_unknown')]);
        }

        if (Language::query()->where('locale', $locale)->exists()) {
            throw ValidationException::withMessages(['locale' => __('tbtop-cms::languages.validation.locale_taken')]);
        }

        return $name;
    }

    /** Blank clears it (og:locale is then omitted); anything else must be language_TERRITORY. */
    private static function guardOgLocale(mixed $value): ?string
    {
        $ogLocale = trim((string) $value);

        if ($ogLocale === '') {
            return null;
        }

        if (! LocaleCatalog::isOgLocale($ogLocale)) {
            throw ValidationException::withMessages(['og_locale' => __('tbtop-cms::languages.validation.og_locale_format')]);
        }

        return $ogLocale;
    }

    /** @return array<string, mixed> */
    public static function load(int $id): array
    {
        $language = Language::query()->findOrFail($id);

        return [
            'name' => $language->name,
            'locale' => $language->locale,
            'og_locale' => $language->og_locale,
            'is_default' => $language->is_default,
            'is_admin_active' => $language->is_admin_active,
            'is_frontend_active' => $language->is_frontend_active,
            'sort_order' => $language->sort_order,
        ];
    }
}
