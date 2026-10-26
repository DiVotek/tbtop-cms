<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Fields;

use Illuminate\Validation\Rule;
use Tbtop\Admin\Media\Models\Media;

/**
 * The fields every page has, whatever its type: the translated texts and
 * SEO under `translations.{locale}` and the two images. Block content is not
 * in v1 (a create gets the type's starter blocks, as in the admin). The
 * description and the rules are both read from FIELDS.
 */
final class CommonFields
{
    /** The body keys a write may carry; anything else at the top level is refused. */
    public const array CREATE_KEYS = ['parent_id', 'translations', 'image', 'og_image', 'fields'];

    public const array UPDATE_KEYS = ['translations', 'image', 'og_image', 'fields'];

    /** key => [kind, label key, translatable, max length] — the SEO keys are `seo.*` under the locale. */
    private const array FIELDS = [
        'name' => [FieldKind::Text, 'name', true, 255],
        'slug' => [FieldKind::Text, 'slug', true, 120],
        'heading' => [FieldKind::Text, 'heading', true, 255],
        'summary' => [FieldKind::Textarea, 'summary', true, null],
        'body' => [FieldKind::Richtext, 'body', true, null],
        'seo.title' => [FieldKind::Text, 'seo_title', true, 255],
        'seo.description' => [FieldKind::Textarea, 'seo_description', true, null],
        'seo.focus_keyword' => [FieldKind::Text, 'seo_focus_keyword', true, 255],
        'seo.og_title' => [FieldKind::Text, 'og_title', true, 255],
        'seo.og_description' => [FieldKind::Textarea, 'og_description', true, null],
        'seo.canonical' => [FieldKind::Text, 'seo_canonical', true, 2048],
        'image' => [FieldKind::Media, 'image', false, null],
        'og_image' => [FieldKind::Media, 'og_image', false, null],
    ];

    /**
     * The text a page is published with: a create needs it in the default
     * locale, publish in every active one. It is `name`, the one text the
     * admin requires; the deprecated `title` column is not written (the meta
     * title is `seo.title`, see UPGRADING "The page `title` field moved").
     */
    public const string REQUIRED = 'name';

    /** @return list<string> */
    public static function translationKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode('.', $key)[0],
            array_keys(array_filter(self::FIELDS, static fn (array $def): bool => $def[2])),
        )));
    }

    /** @return list<string> */
    public static function seoKeys(): array
    {
        $keys = [];
        foreach (array_keys(self::FIELDS) as $key) {
            if (str_starts_with($key, 'seo.')) {
                $keys[] = substr($key, 4);
            }
        }

        return $keys;
    }

    /** @return list<ApiField> */
    public static function all(): array
    {
        $fields = [];
        foreach (self::FIELDS as $key => [$kind, $label, $isTranslatable]) {
            $fields[] = new ApiField(
                key: $key,
                label: __('tbtop-cms::pages.fields.' . $label),
                kind: $kind,
                isRequired: $key === self::REQUIRED,
                isTranslatable: $isTranslatable,
            );
        }

        return $fields;
    }

    /**
     * A name, once sent for the default or a frontend-active locale, may not
     * be blank: publish would refuse the page there, and an update could
     * otherwise empty a live page's name.
     *
     * @param  list<string>  $locales  The writable locales.
     * @param  list<string>  $active  The frontend-active locales.
     * @return array<string, list<mixed>>
     */
    public static function rules(array $locales, array $active, string $default, bool $isCreate): array
    {
        $rules = [
            'translations' => [$isCreate ? 'required' : 'sometimes', 'array:' . implode(',', $locales)],
        ];

        foreach ($locales as $locale) {
            $base = "translations.{$locale}";
            $rules[$base] = ['array:' . implode(',', self::translationKeys())];
            $rules["{$base}.seo"] = ['array:' . implode(',', self::seoKeys())];
            foreach (self::FIELDS as $key => [$kind, , $isTranslatable, $max]) {
                if (! $isTranslatable) {
                    continue;
                }
                $presence = match (true) {
                    $key !== self::REQUIRED => ['nullable'],
                    $isCreate && $locale === $default => ['required'],
                    $locale === $default || in_array($locale, $active, true) => ['sometimes', 'required'],
                    default => ['nullable'],
                };
                $rules["{$base}.{$key}"] = [...$presence, ...self::textRules($kind, $max, $key === 'seo.canonical')];
            }
        }

        $media = ['nullable', 'integer', Rule::exists(Media::class, 'id')];

        return [...$rules, 'image' => $media, 'og_image' => $media];
    }

    /** @return list<mixed> */
    private static function textRules(FieldKind $kind, ?int $max, bool $isUrl): array
    {
        return [
            'string',
            ...($max !== null ? ['max:' . $max] : []),
            ...($isUrl ? ['url:http,https'] : []),
            ...($kind === FieldKind::Richtext ? [new RestrictedHtml] : []),
        ];
    }
}
