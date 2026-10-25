import type { Fixture } from "@tbtop/cms";

/** Lumen Studio — indie product studio (en + de), journal, forms, layout, redirects. */
export const lumenFixture: Fixture = {
	languages: [
		{ locale: "en", name: "English", ogLocale: "en_US", default: true },
		{ locale: "de", name: "Deutsch", ogLocale: "de_DE" },
	],
	settings: {
		"site.name": "Lumen Studio",
		"site.contacts.phones": [{ number: "+49 30 1234 5678" }],
		"site.contacts.emails": [{ address: "hello@lumen.studio.test" }],
		"site.contacts.socials": [
			{ label: "GitHub", name: "GitHub", href: "https://github.com/DiVotek" },
			{ label: "Mastodon", name: "Mastodon", href: "https://mastodon.social/@lumen" },
		],
		"seo.title_template": { en: "{title} · {site_name}", de: "{title} · {site_name}" },
		"seo.default_description": {
			en: "Lumen Studio crafts calm digital products — design systems, apps, and brand sites for teams who care about craft.",
			de: "Lumen Studio entwickelt ruhige digitale Produkte — Designsysteme, Apps und Markenauftritte für Teams mit Anspruch.",
		},
		"seo.identity.type": "organization",
		"seo.identity.name": { en: "Lumen Studio", de: "Lumen Studio" },
		"seo.identity.address.locality": { en: "Berlin", de: "Berlin" },
		"seo.identity.address.country": "DE",
	},
	sharedBlocks: [
		{
			key: "contact",
			name: "Contact form",
			kind: "ContactForm",
			data: {
				heading: { en: "Start a project", de: "Projekt anfragen" },
				submitLabel: { en: "Send message", de: "Nachricht senden" },
			},
		},
		{
			key: "newsletter",
			name: "Newsletter",
			kind: "Newsletter",
			data: {
				heading: { en: "Studio notes", de: "Studio-Notizen" },
				description: {
					en: "Occasional essays on product craft — no spam, unsubscribe anytime.",
					de: "Gelegentliche Essays über Produkt-Handwerk — kein Spam, jederzeit abbestellbar.",
				},
			},
		},
	],
	layout: {
		header: [{ kind: "SiteNav", data: {} }],
		footer: [
			{ kind: "SiteFooter", data: {} },
			{ kind: "Newsletter", shared: "newsletter" },
		],
	},
	pages: [
		{
			key: "home",
			system: true,
			name: { en: "Home", de: "Start" },
			title: { en: "Calm products, crafted with care", de: "Ruhige Produkte, mit Sorgfalt gemacht" },
			summary: {
				en: "Lumen Studio is an indie team building design systems, web apps, and launch sites.",
				de: "Lumen Studio ist ein Indie-Team für Designsysteme, Web-Apps und Launch-Sites.",
			},
			blocks: [
				{
					kind: "StudioHero",
					data: {
						eyebrow: { en: "Berlin · Remote-first", de: "Berlin · Remote-first" },
						title: { en: "We shape luminous digital experiences", de: "Wir gestalten leuchtende digitale Erlebnisse" },
						subtitle: {
							en: "Strategy, design, and engineering for teams who want software that feels inevitable — not rushed.",
							de: "Strategie, Design und Engineering für Teams, die Software wollen, die sich unvermeidlich anfühlt.",
						},
						ctaLabel: { en: "View our work", de: "Unsere Arbeit" },
						ctaHref: "/journal",
						secondaryLabel: { en: "Get in touch", de: "Kontakt" },
						secondaryHref: "/get-in-touch",
					},
				},
				{
					kind: "ServiceGrid",
					data: {
						heading: { en: "What we do", de: "Was wir tun" },
						items: {
							en: [
								{ title: "Product design", body: "Research, flows, and polished UI for web and mobile." },
								{ title: "Design systems", body: "Tokens, components, and docs your team can ship with." },
								{ title: "Full-stack build", body: "TanStack, Laravel, Workers — we meet you where you deploy." },
							],
							de: [
								{ title: "Produktdesign", body: "Research, Flows und UI für Web und Mobile." },
								{ title: "Designsysteme", body: "Tokens, Komponenten und Docs zum Mitliefern." },
								{ title: "Full-Stack", body: "TanStack, Laravel, Workers — dort, wo ihr deployt." },
							],
						},
					},
				},
				{
					kind: "StatBand",
					data: {
						stats: {
							en: [
								{ value: "12+", label: "Launches shipped" },
								{ value: "4.9", label: "Client rating" },
								{ value: "48h", label: "Typical proposal turnaround" },
							],
							de: [
								{ value: "12+", label: "Launches" },
								{ value: "4,9", label: "Kundenbewertung" },
								{ value: "48h", label: "Angebotszeit" },
							],
						},
					},
				},
			],
		},
		{
			key: "about",
			name: { en: "About", de: "Über uns" },
			slug: { en: "about", de: "ueber-uns" },
			summary: { en: "A small studio with senior taste.", de: "Ein kleines Studio mit Senior-Geschmack." },
			body: {
				en: "We are designers and engineers who have shipped at scale and prefer the intimacy of studio work.\n\nLumen means light — we clarify messy product problems and leave teams with systems they can extend.",
				de: "Wir sind Designer und Engineers mit Scale-Erfahrung und der Vorliebe für Studio-Arbeit.\n\nLumen bedeutet Licht — wir klären komplexe Produktfragen und hinterlassen erweiterbare Systeme.",
			},
			seo: { schema: { type: "AboutPage" } },
			blocks: [{ kind: "TeamStrip", data: { heading: { en: "Core team", de: "Kernteam" } } }],
		},
		{
			key: "contact",
			name: { en: "Contact", de: "Kontakt" },
			summary: { en: "Tell us about your project.", de: "Erzählt uns von eurem Projekt." },
			seo: { schema: { type: "ContactPage" } },
			blocks: [{ kind: "ContactForm", shared: "contact" }],
		},
		{
			key: "journal",
			type: "category",
			name: { en: "Journal", de: "Journal" },
			slug: { en: "journal", de: "journal" },
			summary: { en: "Notes on craft, process, and tools.", de: "Notizen zu Handwerk, Prozess und Tools." },
			children: [
				{
					key: "post-design-tokens",
					type: "article",
					subtype: "journal",
					name: { en: "Design tokens that survive handoff", de: "Design-Tokens, die Handoffs überleben" },
					summary: {
						en: "Why semantic tokens beat raw hex in multi-brand systems.",
						de: "Warum semantische Tokens Raw-Hex in Multi-Brand-Systemen schlagen.",
					},
					publishedAt: "2026-09-15T10:00:00Z",
					body: {
						en: "Tokens are contracts between design and code.\n\nStart with intent (surface, emphasis, danger), not palette slots.",
						de: "Tokens sind Verträge zwischen Design und Code.\n\nStartet mit Intent (surface, emphasis, danger), nicht Palette-Slots.",
					},
					fields: { tags: ["design-systems", "process"], readingMinutes: 6 },
					blocks: [
						{
							kind: "PullQuote",
							data: {
								quote: {
									en: "If engineers rename your tokens every sprint, you do not have a system — you have a suggestion.",
									de: "Wenn Engineers eure Tokens jeden Sprint umbenennen, habt ihr kein System — nur einen Vorschlag.",
								},
								attribution: { en: "Lumen playbook", de: "Lumen Playbook" },
							},
						},
					],
				},
				{
					key: "post-motion",
					type: "article",
					subtype: "journal",
					name: { en: "Motion with restraint", de: "Motion mit Zurückhaltung" },
					summary: { en: "When animation helps comprehension versus when it performs.", de: "Wann Animation hilft — und wann sie nur showt." },
					publishedAt: "2026-08-20T08:00:00Z",
					body: { en: "Reach for motion when state changes need explanation.", de: "Setzt Motion ein, wenn sich Zustände erklären lassen." },
					fields: { tags: ["design"], readingMinutes: 4 },
				},
				{
					key: "post-edge-cms",
					type: "article",
					subtype: "journal",
					name: { en: "Headless CMS on the edge", de: "Headless CMS am Edge" },
					summary: { en: "SQLite locally, D1 in production — one content model.", de: "SQLite lokal, D1 in Produktion — ein Content-Modell." },
					publishedAt: "2026-10-01T14:00:00Z",
					body: {
						en: "We dogfood tbtop CMS on Workers to learn where the seams show.\n\nSpoiler: locale routing and forms feel familiar if you know the PHP package.",
						de: "Wir dogfooden tbtop CMS auf Workers, um die Nähte zu sehen.\n\nSpoiler: Locale-Routing und Forms fühlen sich wie im PHP-Paket an.",
					},
					fields: { tags: ["cms", "cloudflare"], readingMinutes: 8 },
				},
				{
					key: "post-scheduled",
					type: "article",
					subtype: "journal",
					name: { en: "Scheduled: 2027 roadmap", de: "Geplant: Roadmap 2027" },
					summary: { en: "Not live until 2027.", de: "Erst 2027 live." },
					publishedAt: "2027-06-01T09:00:00Z",
				},
				{
					key: "post-draft",
					type: "article",
					subtype: "journal",
					name: { en: "Draft essay (hidden)", de: "Entwurf (verborgen)" },
					status: "draft",
					body: { en: "Editors only.", de: "Nur für Redaktion." },
					fields: { tags: ["draft"], readingMinutes: 1 },
				},
			],
		},
		{
			key: "work",
			name: { en: "Selected work", de: "Ausgewählte Arbeit" },
			slug: { en: "work", de: "arbeit" },
			summary: { en: "A few launches we are proud of.", de: "Einige Launches, auf die wir stolz sind." },
			blocks: [
				{
					kind: "CaseStudyList",
					data: {
						cases: {
							en: [
								{ title: "Northwind Analytics", tag: "SaaS", href: "/journal/design-tokens-survive-handoff" },
								{ title: "Harbor Bank", tag: "Fintech", href: "/contact" },
							],
							de: [
								{ title: "Northwind Analytics", tag: "SaaS", href: "/journal/design-tokens-survive-handoff" },
								{ title: "Harbor Bank", tag: "Fintech", href: "/contact" },
							],
						},
					},
				},
			],
		},
	],
	redirects: [{ from: "old-contact", to: "contact", status: 301 }],
};
