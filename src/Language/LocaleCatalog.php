<?php

declare(strict_types=1);

namespace Tbtop\Cms\Language;

/**
 * Static ISO 639-1 code => native language name catalog. Backs the language
 * create form's locale select — creating a Language now picks from this list
 * instead of free-form name+locale input.
 *
 * Deliberately limited to widely-used LTR languages: the admin layout has no
 * RTL support, and rarely-requested locales only pollute the picker.
 */
class LocaleCatalog
{
    /** @var array<string, string> */
    private const LOCALES = [
        'en' => 'English',
        'uk' => 'Українська',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'pl' => 'Polski',
        'cs' => 'Čeština',
        'sk' => 'Slovenčina',
        'ro' => 'Română',
        'bg' => 'Български',
        'el' => 'Ελληνικά',
        'tr' => 'Türkçe',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'sv' => 'Svenska',
        'no' => 'Norsk',
        'da' => 'Dansk',
        'fi' => 'Suomi',
        'et' => 'Eesti',
        'lv' => 'Latviešu',
        'lt' => 'Lietuvių',
        'hu' => 'Magyar',
        'hr' => 'Hrvatski',
        'sr' => 'Српски',
        'sl' => 'Slovenščina',
        'ja' => '日本語',
        'zh' => '中文',
        'ko' => '한국어',
        'hi' => 'हिन्दी',
        'th' => 'ไทย',
        'vi' => 'Tiếng Việt',
        'id' => 'Bahasa Indonesia',
    ];

    /**
     * ogp.me wants language_TERRITORY; the territory is the one a site in
     * that language most often targets. `no` maps to nb_NO because Facebook's
     * scraper rejects the macro-language code.
     *
     * @var array<string, string>
     */
    private const OG_LOCALES = [
        'en' => 'en_US', 'uk' => 'uk_UA', 'de' => 'de_DE', 'fr' => 'fr_FR',
        'es' => 'es_ES', 'it' => 'it_IT', 'pl' => 'pl_PL', 'cs' => 'cs_CZ',
        'sk' => 'sk_SK', 'ro' => 'ro_RO', 'bg' => 'bg_BG', 'el' => 'el_GR',
        'tr' => 'tr_TR', 'pt' => 'pt_PT', 'nl' => 'nl_NL', 'sv' => 'sv_SE',
        'no' => 'nb_NO', 'da' => 'da_DK', 'fi' => 'fi_FI', 'et' => 'et_EE',
        'lv' => 'lv_LV', 'lt' => 'lt_LT', 'hu' => 'hu_HU', 'hr' => 'hr_HR',
        'sr' => 'sr_RS', 'sl' => 'sl_SI', 'ja' => 'ja_JP', 'zh' => 'zh_CN',
        'ko' => 'ko_KR', 'hi' => 'hi_IN', 'th' => 'th_TH', 'vi' => 'vi_VN',
        'id' => 'id_ID',
    ];

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::LOCALES;
    }

    public static function name(string $code): ?string
    {
        return self::LOCALES[$code] ?? null;
    }

    public static function defaultOgLocale(string $code): ?string
    {
        return self::OG_LOCALES[$code] ?? null;
    }

    public static function isOgLocale(string $value): bool
    {
        return preg_match('/^[a-z]{2,3}_[A-Z]{2}$/', $value) === 1;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        $options = [];

        foreach (self::LOCALES as $code => $name) {
            $options[] = ['value' => $code, 'label' => "{$name} ({$code})"];
        }

        return $options;
    }
}
