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
		"forms.submit_failed": "Sorry, we could not process your submission. Please try again.",
		"notifications.mail.subject": "New form submission",
		"notifications.mail.fields.page": "Page",
		"notifications.mail.fields.locale": "Locale",
		"notifications.mail.fields.submitted_at": "Submitted",
		"notifications.mail.view_submission": "View submission",
		"notifications.bell.title": "New form submission",
		"notifications.bell.view": "View submission",
		"notifications.bell.open": "Open",
		"notifications.telegram.message_title": "New form submission",
		"notifications.channels.mail": "Email",
		"notifications.channels.telegram": "Telegram",
		"notifications.channels.bell": "Bell",
		"notifications.errors.bell": "Bell notification failed: :error",
		"notifications.errors.mail": "Mail delivery failed: :error",
		"notifications.errors.channel": ":channel delivery failed: :error",
		"notifications.errors.render": "Couldn't render the :channel notification: :error",
		"notifications.errors.mail_no_recipients": "Mail notifications are enabled but no recipients are configured.",
		"notifications.errors.telegram": "Telegram delivery failed: :error",
		"notifications.errors.telegram_not_configured": "Telegram notifications are enabled but the bot token or chat IDs are not configured.",
		"form-submissions.date_format": "M j, Y H:i",
		"form-submissions.values.page_number": "Page #:id",
		"form-submissions.values.lead_unknown": "—",
		"form-submissions.errors.retry_unfinished": "Retry did not finish.",
		"form-submissions.errors.page_missing": "Page no longer exists.",
		"form-submissions.errors.block_missing": "Form block no longer exists on the page.",
	},
	uk: {
		"forms.contact.heading": "Зв’яжіться з нами",
		"forms.contact.submit": "Надіслати",
		"forms.contact.success": "Дякуємо, ми з вами зв’яжемося.",
		"forms.contact.fields.name": "Ім’я",
		"forms.contact.fields.email": "Електронна пошта",
		"forms.contact.fields.message": "Повідомлення",
		"pages.preview.banner": "Попередній перегляд чернетки — сторінку не опубліковано.",
		"forms.submit_failed": "Вибачте, не вдалося обробити вашу заявку. Спробуйте ще раз.",
		"notifications.mail.subject": "Нова заявка з форми",
		"notifications.mail.fields.page": "Сторінка",
		"notifications.mail.fields.locale": "Мова",
		"notifications.mail.fields.submitted_at": "Надіслано",
		"notifications.mail.view_submission": "Переглянути заявку",
		"notifications.bell.title": "Нова заявка з форми",
		"notifications.bell.view": "Переглянути заявку",
		"notifications.bell.open": "Відкрити",
		"notifications.telegram.message_title": "Нова заявка з форми",
		"notifications.channels.mail": "Email",
		"notifications.channels.telegram": "Telegram",
		"notifications.channels.bell": "Дзвіночок",
		"notifications.errors.bell": "Не вдалося надіслати сповіщення дзвіночком: :error",
		"notifications.errors.mail": "Не вдалося надіслати лист: :error",
		"notifications.errors.channel": "Не вдалося надіслати сповіщення :channel: :error",
		"notifications.errors.render": "Не вдалося сформувати сповіщення :channel: :error",
		"notifications.errors.mail_no_recipients": "Поштові сповіщення увімкнено, але отримувачів не налаштовано.",
		"notifications.errors.telegram": "Не вдалося надіслати повідомлення в Telegram: :error",
		"notifications.errors.telegram_not_configured": "Telegram-сповіщення увімкнено, але токен бота або ID чатів не налаштовано.",
		"form-submissions.date_format": "d.m.Y H:i",
		"form-submissions.values.page_number": "Сторінка №:id",
		"form-submissions.values.lead_unknown": "—",
		"form-submissions.errors.retry_unfinished": "Повторну спробу не завершено.",
		"form-submissions.errors.page_missing": "Сторінки більше не існує.",
		"form-submissions.errors.block_missing": "Блоку форми більше немає на сторінці.",
	},
};

/** __(): the locale's string, else the fallback locale's, else English, else the key; `:name` placeholders filled. */
export function translate(
	key: string,
	locale: string,
	fallback: string,
	extra: Record<string, Record<string, string>> = {},
	params: Record<string, string | number> = {},
): string {
	for (const candidate of [locale, fallback, "en"]) {
		const text = extra[candidate]?.[key] ?? STRINGS[candidate]?.[key];
		if (text !== undefined) {
			return fill(text, params);
		}
	}
	return key;
}

/** Whether a package or host string exists for the key in any locale. */
export const hasString = (key: string, extra: Record<string, Record<string, string>> = {}): boolean =>
	[...Object.values(STRINGS), ...Object.values(extra)].some((strings) => key in strings);

const fill = (text: string, params: Record<string, string | number>): string =>
	Object.keys(params)
		.sort((a, b) => b.length - a.length)
		.reduce((out, name) => out.replaceAll(`:${name}`, String(params[name])), text);
