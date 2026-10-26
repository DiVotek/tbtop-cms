<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Fields;

use Illuminate\Validation\Rule;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Block\BlockRegistry;

/**
 * The fields every page has, whatever its type: the translated texts and
 * SEO under `translations.{locale}`, the two images and the block content.
 * The description and the rules are both read from FIELDS.
 */
final class CommonFields
{
    /** key => [kind, label key, translatable, max length] — the SEO keys are `seo.*` under the locale. */
    private const array FIELDS = [
        'name' => [FieldKind::Text, 'name', true, 255],
        'title' => [FieldKind::Text, 'title', true, 255],
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
        'content' => [FieldKind::Blocks, null, false, null],
    ];

    /** The texts a page is published with: a create needs it in the default locale, publish in every active one. */
    public const string REQUIRED = 'title';

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
                label: $label === null ? __('tbtop-cms::pages.blocks.section_title') : __('tbtop-cms::pages.fields.' . $label),
                kind: $kind,
                isRequired: $key === self::REQUIRED,
                isTranslatable: $isTranslatable,
            );
        }

        return $fields;
    }

    /**
     * @param  list<string>  $locales  The writable locales.
     * @param  list<string>  $placements  The page's type and subtype, for the block kinds it may hold.
     * @return array<string, list<mixed>>
     */
    public static function rules(array $locales, string $default, bool $isCreate, array $placements): array
    {
        $rules = [
            'translations' => [$isCreate ? 'required' : 'sometimes', 'array:' . implode(',', $locales)],
        ];

        foreach ($locales as $locale) {
            $base = "translations.{$locale}";
            $rules[$base] = ['array:' . implode(',', self::translationKeys())];
            $rules["{$base}.seo"] = ['array:' . implode(',', self::seoKeys())];
            foreach (self::FIELDS as $key => [$kind, , $isTranslatable, $max]) {
                if ($isTranslatable) {
                    $isRequired = $isCreate && $key === self::REQUIRED && $locale === $default;
                    $rules["{$base}.{$key}"] = self::textRules($kind, $max, $key === 'seo.canonical', $isRequired);
                }
            }
        }

        $media = ['nullable', 'integer', Rule::exists(Media::class, 'id')];
        $kinds = array_keys(app(BlockRegistry::class)->forPlacements($placements));

        return [
            ...$rules,
            'image' => $media,
            'og_image' => $media,
            'content' => ['array'],
            'content.*' => ['array:id,kind,active,data'],
            'content.*.id' => ['nullable', 'string', 'max:64'],
            'content.*.kind' => ['required', 'string', Rule::in($kinds)],
            'content.*.active' => ['boolean'],
            'content.*.data' => ['array'],
        ];
    }

    /** @return list<mixed> */
    private static function textRules(FieldKind $kind, ?int $max, bool $isUrl, bool $isRequired): array
    {
        return [
            $isRequired ? 'required' : 'nullable',
            'string',
            ...($max !== null ? ['max:' . $max] : []),
            ...($isUrl ? ['url:http,https'] : []),
            ...($kind === FieldKind::Richtext ? [new RestrictedHtml] : []),
        ];
    }
}
