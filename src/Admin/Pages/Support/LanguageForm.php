<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Language\Models\Language;

class LanguageForm
{
    /** @return list<Field> */
    public static function fields(S $s, bool $isEdit = false): array
    {
        $locale = $s->text('locale')->label(__('tbtop-cms::languages.fields.locale'))->required()->rules('max:5')
            ->placeholder(__('tbtop-cms::languages.placeholders.locale'));

        if ($isEdit) {
            $locale->meta('disabled', true)->helperText(__('tbtop-cms::languages.helpers.locale'));
        }

        return [
            $s->text('name')->label(__('tbtop-cms::languages.fields.name'))->required(),
            $locale,
            $s->boolean('is_default')->label(__('tbtop-cms::languages.fields.is_default')),
            $s->boolean('is_admin_active')->label(__('tbtop-cms::languages.fields.is_admin_active')),
            $s->boolean('is_frontend_active')->label(__('tbtop-cms::languages.fields.is_frontend_active')),
            $s->number('sort_order')->label(__('tbtop-cms::languages.fields.sort_order')),
        ];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Language
    {
        $language = $id !== null ? Language::query()->findOrFail($id) : new Language;
        $language->fill([
            'name' => (string) ($form['name'] ?? ''),
            'is_default' => (bool) ($form['is_default'] ?? false),
            'is_admin_active' => (bool) ($form['is_admin_active'] ?? false),
            'is_frontend_active' => (bool) ($form['is_frontend_active'] ?? false),
            'sort_order' => (int) ($form['sort_order'] ?? 0),
        ]);

        if (! $language->exists) {
            $locale = (string) ($form['locale'] ?? '');
            self::guardLocale($locale);
            $language->locale = $locale;
        }

        $language->save();

        return $language;
    }

    private static function guardLocale(string $locale): void
    {
        if ($locale === '') {
            throw ValidationException::withMessages(['locale' => __('tbtop-cms::languages.validation.locale_required')]);
        }

        if (Language::query()->where('locale', $locale)->exists()) {
            throw ValidationException::withMessages(['locale' => __('tbtop-cms::languages.validation.locale_taken')]);
        }
    }

    /** @return array<string, mixed> */
    public static function load(int $id): array
    {
        $language = Language::query()->findOrFail($id);

        return [
            'name' => $language->name,
            'locale' => $language->locale,
            'is_default' => $language->is_default,
            'is_admin_active' => $language->is_admin_active,
            'is_frontend_active' => $language->is_frontend_active,
            'sort_order' => $language->sort_order,
        ];
    }
}
