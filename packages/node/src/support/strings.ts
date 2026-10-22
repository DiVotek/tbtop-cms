/** Frontend strings the package itself renders, per locale (resources/lang of the PHP package). */
export const STRINGS: Record<string, Record<string, string>> = {
	en: {
		"forms.contact.heading": "Contact us",
		"forms.contact.submit": "Send",
		"forms.contact.success": "Thanks, we will be in touch.",
		"forms.contact.fields.name": "Name",
		"forms.contact.fields.email": "Email",
		"forms.contact.fields.message": "Message",
		"pages.preview.banner": "Draft preview — this page is not published.",
	},
	uk: {
		"forms.contact.heading": "Зв’яжіться з нами",
		"forms.contact.submit": "Надіслати",
		"forms.contact.success": "Дякуємо, ми з вами зв’яжемося.",
		"forms.contact.fields.name": "Ім’я",
		"forms.contact.fields.email": "Електронна пошта",
		"forms.contact.fields.message": "Повідомлення",
		"pages.preview.banner": "Попередній перегляд чернетки — сторінку не опубліковано.",
	},
};

/** __(): the locale's string, else the fallback locale's, else English, else the key. */
export function translate(
	key: string,
	locale: string,
	fallback: string,
	extra: Record<string, Record<string, string>> = {},
): string {
	for (const candidate of [locale, fallback, "en"]) {
		const text = extra[candidate]?.[key] ?? STRINGS[candidate]?.[key];
		if (text !== undefined) {
			return text;
		}
	}
	return key;
}
