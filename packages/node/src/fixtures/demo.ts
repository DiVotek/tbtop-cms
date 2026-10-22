import type { Fixture } from "./seed";

/**
 * The demo site: English (default) and Ukrainian, a home page, a blog
 * category with articles (one scheduled for 2099), a draft, a shared
 * contact form, a redirect. Only the built-in Hero and ContactForm blocks.
 */
export const demoFixture: Fixture = {
	languages: [
		{ locale: "en", name: "English", ogLocale: "en_US", default: true },
		{ locale: "uk", name: "Українська", ogLocale: "uk_UA" },
	],
	settings: {
		"site.name": "tbtop demo",
		"site.contacts.phones": [{ number: "+380 44 000 00 00" }],
		"site.contacts.emails": [{ address: "hello@example.test" }],
		"site.contacts.socials": [{ label: "GH", name: "GitHub", href: "https://github.com/DiVotek" }],
		"seo.title_template": { en: "{title} {sep} {site_name}", uk: "{title} {sep} {site_name}" },
		"seo.default_description": { en: "A demo site on tbtop CMS.", uk: "Демо-сайт на tbtop CMS." },
		"seo.identity.type": "organization",
		"seo.identity.address.locality": { en: "Kyiv", uk: "Київ" },
		"seo.identity.address.country": "UA",
	},
	sharedBlocks: [
		{
			key: "contact",
			name: "Contact form",
			kind: "ContactForm",
			data: { heading: { en: "Write to us", uk: "Напишіть нам" } },
		},
	],
	pages: [
		{
			key: "home",
			system: true,
			name: { en: "Home", uk: "Головна" },
			title: { en: "Welcome", uk: "Ласкаво просимо" },
			summary: { en: "The tbtop CMS demo home page.", uk: "Головна сторінка демо tbtop CMS." },
			blocks: [
				{
					kind: "Hero",
					data: {
						title: { en: "Welcome to tbtop CMS", uk: "Вітаємо в tbtop CMS" },
						subtitle: { en: "This hero block is rendered through json-render.", uk: "" },
						ctaLabel: { en: "Read the blog", uk: "Читати блог" },
						ctaHref: "/blog",
					},
				},
				{ kind: "ContactForm", shared: "contact" },
			],
		},
		{
			key: "about",
			name: { en: "About us", uk: "Про нас" },
			summary: { en: "Who we are.", uk: "Хто ми." },
			body: { en: "We build small, fast sites.\n\nThis page has a body and no blocks.", uk: "Ми робимо невеликі швидкі сайти." },
			seo: { schema: { type: "AboutPage" } },
		},
		{
			key: "blog",
			type: "category",
			name: { en: "Blog", uk: "Блог" },
			children: [
				{
					key: "first-post",
					type: "article",
					name: { en: "First post", uk: "Перший запис" },
					summary: { en: "The first article.", uk: "Перша стаття." },
					publishedAt: "2026-10-01T09:00:00Z",
					body: { en: "Hello from the first article.", uk: "Привіт з першої статті." },
					blocks: [{ kind: "Hero", data: { title: { en: "First post", uk: "Перший запис" } } }],
				},
				{
					key: "scheduled-post",
					type: "article",
					name: { en: "Coming soon", uk: "Незабаром" },
					publishedAt: "2099-01-01T00:00:00Z",
				},
			],
		},
		{ key: "draft", name: { en: "Work in progress" }, status: "draft" },
	],
	redirects: [{ from: "old-about", to: "about-us" }],
};
