/**
 * ISO 639-1 code to native name (LocaleCatalog::LOCALES): the languages the
 * admin's "Add language" select offers. Widely used LTR languages only: the
 * admin layout has no RTL support.
 */
const LOCALES: Record<string, string> = {
	en: "English",
	uk: "Українська",
	de: "Deutsch",
	fr: "Français",
	es: "Español",
	it: "Italiano",
	pl: "Polski",
	cs: "Čeština",
	sk: "Slovenčina",
	ro: "Română",
	bg: "Български",
	el: "Ελληνικά",
	tr: "Türkçe",
	pt: "Português",
	nl: "Nederlands",
	sv: "Svenska",
	no: "Norsk",
	da: "Dansk",
	fi: "Suomi",
	et: "Eesti",
	lv: "Latviešu",
	lt: "Lietuvių",
	hu: "Magyar",
	hr: "Hrvatski",
	sr: "Српски",
	sl: "Slovenščina",
	ja: "日本語",
	zh: "中文",
	ko: "한국어",
	hi: "हिन्दी",
	th: "ไทย",
	vi: "Tiếng Việt",
	id: "Bahasa Indonesia",
};

/** The native name of a catalog locale; null for one the catalog does not list. */
export function localeName(locale: string): string | null {
	return Object.hasOwn(LOCALES, locale) ? (LOCALES[locale] ?? null) : null;
}

/** The catalog as select options, `Deutsch (de)` (LocaleCatalog::options). */
export function localeOptions(): { value: string; label: string }[] {
	return Object.entries(LOCALES).map(([value, name]) => ({ value, label: `${name} (${value})` }));
}

/**
 * The og:locale a new language gets when none is given (LocaleCatalog::OG_LOCALES):
 * the territory a site in that language most often targets. `no` maps to
 * nb_NO because Facebook's scraper rejects the macro-language code.
 */
const OG_LOCALES: Record<string, string> = {
	en: "en_US",
	uk: "uk_UA",
	de: "de_DE",
	fr: "fr_FR",
	es: "es_ES",
	it: "it_IT",
	pl: "pl_PL",
	cs: "cs_CZ",
	sk: "sk_SK",
	ro: "ro_RO",
	bg: "bg_BG",
	el: "el_GR",
	tr: "tr_TR",
	pt: "pt_PT",
	nl: "nl_NL",
	sv: "sv_SE",
	no: "nb_NO",
	da: "da_DK",
	fi: "fi_FI",
	et: "et_EE",
	lv: "lv_LV",
	lt: "lt_LT",
	hu: "hu_HU",
	hr: "hr_HR",
	sr: "sr_RS",
	sl: "sl_SI",
	ja: "ja_JP",
	zh: "zh_CN",
	ko: "ko_KR",
	hi: "hi_IN",
	th: "th_TH",
	vi: "vi_VN",
	id: "id_ID",
};

export function defaultOgLocale(locale: string): string | null {
	return Object.hasOwn(OG_LOCALES, locale) ? (OG_LOCALES[locale] ?? null) : null;
}

/** ogp.me's language_TERRITORY (LocaleCatalog::isOgLocale). */
export function isOgLocale(value: string): boolean {
	return /^[a-z]{2,3}_[A-Z]{2}$/.test(value);
}
