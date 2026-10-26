import { z } from "zod";
import type { Fixture, PageProps } from "../../../src";

/**
 * The reference host every variant builds (a small company site, as the
 * divotek.com.ua port): the same content, extension points and checks, so
 * the variants differ only in how the host says it.
 *
 * - blocks: the opt-in Hero and the host's Promo (translatable title, link CTA)
 * - page types: the built-in `page` extended with `tagline`; a new `service`
 *   (price, duration) whose JSON-LD is the host's `Service` schema type
 * - SEO: `seo.extend` puts the host's Organization at the identity @id
 * - settings: a "Company" tab on Site settings with `phone` (site.phone)
 * - auth: Better Auth from `db`, an admin seeded idempotently
 * - Cloudflare: a Worker entry (fetch + scheduled) on D1, KV and R2
 */
export const fixture: Fixture = {
	languages: [
		{ locale: "uk", name: "Українська", default: true },
		{ locale: "en", name: "English" },
	],
	settings: { "site.name": "Divotek", "site.phone": "+380 44 000 00 00" },
	pages: [
		{
			key: "home",
			system: true,
			name: { uk: "Головна", en: "Home" },
			fields: { tagline: { uk: "Сайти, що продають", en: "Sites that sell" } },
			blocks: [
				{ kind: "Hero", data: { title: { uk: "Divotek", en: "Divotek" } } },
				{ kind: "Promo", data: { title: { uk: "Замовити аудит", en: "Order an audit" }, cta: { type: "external", value: "https://divotek.com.ua/audit", label: { uk: "Так", en: "Yes" } } } },
			],
		},
		{
			key: "audit",
			type: "service",
			name: { uk: "SEO-аудит", en: "SEO audit" },
			slug: { uk: "audit", en: "audit" },
			fields: { price: 1200, duration: { uk: "2 тижні", en: "2 weeks" } },
		},
	],
};

export const ADMIN = { email: "admin@divotek.test", password: "correct-horse-battery" };

/** The Promo block's props (client-safe: zod only). */
export const promoProps = z.object({
	title: z.string().nullable(),
	cta: z.object({ href: z.string(), type: z.string(), label: z.string() }).nullable(),
});

/** The host's Service schema node fields, used by every variant's schema type. */
export const serviceNode = (base: Record<string, unknown>, providerId: string, price: unknown) => ({
	...base,
	provider: { "@id": providerId },
	...(typeof price === "number" ? { offers: { "@type": "Offer", price, priceCurrency: "UAH" } } : {}),
});

/** The host's Organization, replacing the identity node by its @id. */
export const organization = (id: string) => ({
	"@context": "https://schema.org",
	"@type": "Organization",
	"@id": id,
	name: "Divotek",
	sameAs: ["https://github.com/DiVotek"],
});

/** JSON inside <script>: a `</script>` in a page name or setting must not close the tag. */
const jsonInScript = (value: unknown) => JSON.stringify(value).replace(/[<>&\u2028\u2029]/g, (c) => `\\u${c.charCodeAt(0).toString(16).padStart(4, "0")}`);

const esc = (text: unknown) => String(text ?? "").replace(/[&<>"]/g, (c) => `&#${c.charCodeAt(0)};`);

/**
 * The host's page renderer (a stand-in for its React page): the typed
 * `extra` line is where each variant proves `page.fields` is typed.
 */
export function html(props: PageProps, extra: string): string {
	const blocks = (props.render.elements.$root?.children ?? []).map((id) => props.render.elements[id]);
	return `<!doctype html><title>${esc(props.seo.title)}</title>
${props.seo.jsonLd.map((node) => `<script type="application/ld+json">${jsonInScript(node)}</script>`).join("")}
<h1>${esc(props.page.name)}</h1><p class="extra">${esc(extra)}</p>
${blocks.map((block) => `<section data-kind="${esc(block?.type)}">${esc(JSON.stringify(block?.props))}</section>`).join("")}`;
}
