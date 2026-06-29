<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Language\Models\Language;

class LanguageForm
{
    /** @return list<Field> */
    public static function fields(S $s, bool $isEdit = false): array
    {
        $locale = $s->text('locale')->label('Locale')->required()->rules('max:5');

        if ($isEdit) {
            $locale->meta('disabled', true)->helperText('Locale cannot be changed after creation.');
        }

        return [
            $s->text('name')->label('Name')->required(),
            $locale,
            $s->checkbox('is_default')->label('Default language'),
            $s->checkbox('is_admin_active')->label('Active in admin'),
            $s->checkbox('is_frontend_active')->label('Active on site'),
            $s->number('sort_order')->label('Sort order'),
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
            $language->locale = (string) ($form['locale'] ?? '');
        }

        $language->save();

        return $language;
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
