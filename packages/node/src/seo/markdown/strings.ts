import { translate } from "../../support/strings";

/** The page-markdown strings (the `markdown.*` keys of the PHP llms.php lang files). */
const STRINGS: Record<string, Record<string, string>> = {
	en: {
		"markdown.children": "In this section",
		"markdown.about": "About :site",
		"markdown.form": "This page has a contact form.",
	},
	uk: {
		"markdown.children": "У цьому розділі",
		"markdown.about": "Про :site",
		"markdown.form": "На цій сторінці є форма зворотного звʼязку.",
	},
};

export const markdownString = (key: string, locale: string, params: Record<string, string> = {}): string => translate(`markdown.${key}`, locale, "en", STRINGS, params);
