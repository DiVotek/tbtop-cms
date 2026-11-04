import type { Fixture } from "@tbtop/cms";

/** The content seeded into an empty site on the first boot. */
export const fixture: Fixture = {
	languages: [
		{ locale: "uk", name: "Українська", default: true },
		{ locale: "en", name: "English" },
	],
	settings: { "site.name": "Acme", "site.phone": "+380 44 000 00 00", "site.address": { uk: "Київ", en: "Kyiv" } },
	pages: [
		{
			key: "home",
			system: true,
			name: { uk: "Головна", en: "Home" },
			fields: { tagline: { uk: "Сайти, що продають", en: "Sites that sell" } },
			blocks: [
				{ kind: "Hero", data: { title: { uk: "Acme", en: "Acme" }, cta: { type: "external", value: "https://example.com/audit", label: { uk: "Аудит", en: "Audit" } } } },
				{ kind: "Promo", data: { title: { uk: "Замовити аудит", en: "Order an audit" }, cta: { type: "external", value: "https://example.com/audit", label: { uk: "Так", en: "Yes" } } } },
				{ kind: "Faq", data: { heading: { uk: "Питання", en: "FAQ" }, items: [{ question: { uk: "Скільки?", en: "How much?" }, answer: { uk: "Від 1200 грн", en: "From 1200 UAH" } }] } },
				{ kind: "Contacts", data: { heading: { uk: "Контакти", en: "Contacts" } } },
				{ kind: "Callback", data: { heading: { uk: "Передзвонимо", en: "We call back" }, button: { uk: "Чекаю", en: "Call me" } } },
			],
		},
		{
			key: "audit",
			type: "service",
			name: { uk: "SEO-аудит", en: "SEO audit" },
			slug: { uk: "audit", en: "audit" },
			fields: { price: 1200, duration: { uk: "2 тижні", en: "2 weeks" }, level: "pro", channels: ["web", "seo"] },
		},
	],
};
