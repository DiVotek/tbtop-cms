import type { Page } from "../content/page";
import { pageFromRow } from "../content/page";
import { routeLocale } from "../frontend/load";
import { whereLive } from "../content/pages";
import type { Site } from "../site";
import { translate } from "../support/strings";
import { indexingEnabled } from "./resolver";
import { SiteIdentity } from "./identity";
import { localizedWithFallback } from "./localized";
import { markdownEnabled, markdownPath } from "./markdown/context";
import { ownDescription, ownTitle } from "./schemaContext";

/** One `- [name](url): notes` line of an llms.txt file list; a relative URL is made absolute on render. */
export type LlmsTxtLink = { name: string; url: string; notes?: string | null };

/** A host section of llms.txt (`llmsTxt.sections`): one H2 file list after the page-type sections; no links, no section. */
export type LlmsTxtSection = {
	title: (locale: string) => string;
	links: (locale: string, site: Site) => LlmsTxtLink[] | Promise<LlmsTxtLink[]>;
};

export const DEFAULT_PER_TYPE = 50;
export const MAX_PER_TYPE = 500;
/** Rows read per tree-ordered type before sorting by depth, which comes from the slug path (LlmsTxtPages::TREE_CANDIDATES). */
const TREE_CANDIDATES = 1000;

const STRINGS: Record<string, Record<string, string>> = {
	en: {
		"sections.page": "Pages",
		"sections.category": "Categories",
		"sections.article": "Articles",
		"contacts.phone": "Phone",
		"contacts.email": "Email",
		"contacts.address": "Address",
		"contacts.hours": "Opening hours",
		"optional.sitemap": "Sitemap",
		"optional.sitemap_notes": "Every indexable URL of the site",
		"optional.language_notes": "This file in another language",
		"days.monday": "Monday",
		"days.tuesday": "Tuesday",
		"days.wednesday": "Wednesday",
		"days.thursday": "Thursday",
		"days.friday": "Friday",
		"days.saturday": "Saturday",
		"days.sunday": "Sunday",
	},
	uk: {
		"sections.page": "Сторінки",
		"sections.category": "Категорії",
		"sections.article": "Статті",
		"contacts.phone": "Телефон",
		"contacts.email": "Email",
		"contacts.address": "Адреса",
		"contacts.hours": "Графік роботи",
		"optional.sitemap": "Карта сайту",
		"optional.sitemap_notes": "Усі індексовані URL сайту",
		"optional.language_notes": "Цей файл іншою мовою",
		"days.monday": "Понеділок",
		"days.tuesday": "Вівторок",
		"days.wednesday": "Середа",
		"days.thursday": "Четвер",
		"days.friday": "Пʼятниця",
		"days.saturday": "Субота",
		"days.sunday": "Неділя",
	},
};

const t = (key: string, locale: string): string => translate(key, locale, "en", STRINGS);

/** LlmsTxtMarkdown::inline: whitespace, newlines included, collapsed to single spaces. */
const inline = (text: string): string => text.replace(/\s+/gu, " ").trim();

const linkText = (text: string): string => inline(text).replaceAll("\\", "\\\\").replaceAll("[", "\\[").replaceAll("]", "\\]");

/** LlmsTxtMarkdown::block: free text minus ATX headings, setext underlines and runs of blank lines. */
export const notesBlock = (text: string): string =>
	text
		.replaceAll("\r\n", "\n")
		.replace(/^[ \t]*#{1,6}[ \t]+/gmu, "")
		.replace(/^[ \t]*(=+|-+)[ \t]*$/gmu, "")
		.replace(/\n{3,}/g, "\n\n")
		.trim();

/** LlmsTxtMarkdown::url: absolute, with the characters that would end a markdown link encoded. */
function linkUrl(site: Site, url: string): string {
	const codes: Record<string, string> = { " ": "%20", "(": "%28", ")": "%29", "<": "%3C", ">": "%3E", "\t": "%09", "\n": "%0A", "\r": "%0D" };
	return site.absolute(url.trim()).replace(/[ ()<>\t\n\r]/g, (char) => codes[char] ?? char);
}

/** LlmsTxtSettings::enabled: off until an editor turns it on; `llmsTxt.enabled: false` removes the route. */
export async function llmsTxtEnabled(site: Site): Promise<boolean> {
	return site.config.llmsTxt?.enabled !== false && Boolean(await site.settings.get("seo.llms_txt.enabled", false));
}

export async function perType(site: Site): Promise<number> {
	const stored = Number(await site.settings.get("seo.llms_txt.per_type"));
	return Number.isInteger(stored) && stored >= 1 ? Math.min(stored, MAX_PER_TYPE) : DEFAULT_PER_TYPE;
}

/** Nothing ticked means every base type. */
async function listedTypes(site: Site): Promise<string[]> {
	const stored = await site.settings.get("seo.llms_txt.types");
	const chosen = Array.isArray(stored) ? stored.filter((type): type is string => typeof type === "string") : [];
	return site.pageTypes
		.bases()
		.map((type) => type.key)
		.filter((key) => chosen.length === 0 || chosen.includes(key));
}

/** Live, indexable, non-error pages of a type with a slug in the locale (LlmsTxtPages::query). */
function query(site: Site, type: string, locale: string) {
	return whereLive(site.pages.query())
		.selectAll("tbtop_pages")
		.where("tbtop_pages.type", "=", type)
		.where("tbtop_pages.is_indexable", "=", 1)
		.where("tbtop_pages.error_class", "is", null)
		.where("tbtop_pages.id", "in", (eb) => eb.selectFrom("tbtop_page_slugs").select("page_id").where("locale", "=", locale));
}

/** Articles newest first; every other type home first, then shallower before deeper, then the editor's order. */
async function pagesOf(site: Site, type: string, locale: string, limit: number): Promise<{ page: Page; path: string }[]> {
	if (type === "article") {
		const rows = await query(site, type, locale).orderBy("tbtop_pages.published_at", "desc").orderBy("tbtop_pages.id", "desc").limit(limit).execute();
		const pages = rows.map(pageFromRow);
		const paths = await site.pages.slugs.pathsFor(pages.map((page) => page.id), locale);
		return pages.map((page) => ({ page, path: paths.get(page.id) ?? "" }));
	}
	const rows = await query(site, type, locale).orderBy("tbtop_pages.sort_order").orderBy("tbtop_pages.id").limit(TREE_CANDIDATES).execute();
	const pages = rows.map(pageFromRow);
	const paths = await site.pages.slugs.pathsFor(pages.map((page) => page.id), locale);
	const keyed = pages.map((page, index) => {
		const path = paths.get(page.id) ?? "";
		const trimmed = path.replace(/^\/+|\/+$/g, "");
		return { page, path, key: [trimmed === "" ? 0 : 1, trimmed.split("/").length - 1, page.sortOrder, index] };
	});
	keyed.sort((a, b) => {
		for (let i = 0; i < a.key.length; i++) {
			const diff = (a.key[i] ?? 0) - (b.key[i] ?? 0);
			if (diff !== 0) {
				return diff;
			}
		}
		return 0;
	});
	return keyed.slice(0, limit);
}

async function pageSections(site: Site, locale: string): Promise<{ title: string; links: LlmsTxtLink[] }[]> {
	const limit = await perType(site);
	const markdown = await markdownEnabled(site);
	const sections: { title: string; links: LlmsTxtLink[] }[] = [];
	for (const key of await listedTypes(site)) {
		const links: LlmsTxtLink[] = [];
		for (const { page, path } of await pagesOf(site, key, locale, limit)) {
			// Links point at the page's `.md` while markdown versions are on.
			const url = markdown ? await markdownPath(site, path, locale) : await site.url(path, locale);
			links.push({ name: await ownTitle(site, page, locale), url, notes: await ownDescription(site, page, locale) });
		}
		if (links.length > 0) {
			const builtIn = STRINGS.en?.[`sections.${key}`] !== undefined;
			sections.push({ title: builtIn ? t(`sections.${key}`, locale) : site.pageTypes.get(key).label, links });
		}
	}
	return sections;
}

/** LlmsTxtContacts: phones, emails, address and opening hours, then the social links. */
export async function contactLines(site: Site, locale: string, fallback: string): Promise<string[]> {
	const lines: string[] = [];
	const add = (label: string, value: string) => {
		if (value !== "") {
			lines.push(`- ${t(`contacts.${label}`, locale)}: ${inline(value)}`);
		}
	};
	const identity = await new SiteIdentity(site.settings, site.contacts).properties(locale, fallback);
	add("phone", (await site.contacts.phones(locale)).join(", "));
	add("email", (await site.contacts.emails(locale)).join(", "));
	const address = (identity.address ?? {}) as Record<string, unknown>;
	add(
		"address",
		["streetAddress", "addressLocality", "addressRegion", "postalCode", "addressCountry"]
			.map((field) => address[field])
			.filter((part): part is string => typeof part === "string" && part !== "")
			.join(", "),
	);
	const specs = Array.isArray(identity.openingHoursSpecification) ? (identity.openingHoursSpecification as Record<string, unknown>[]) : [];
	add(
		"hours",
		specs
			.map((spec) => {
				const days = (spec.dayOfWeek as string[]).map((url) => t(`days.${(url.split("/").pop() ?? "").toLowerCase()}`, locale));
				return `${days.join(", ")} ${String(spec.opens)}–${String(spec.closes)}`;
			})
			.join("; "),
	);
	for (const social of await site.contacts.socials(locale)) {
		lines.push(`- ${inline(social.label)}: ${linkUrl(site, social.href)}`);
	}
	return lines;
}

/** The H1: the site name, else the origin's host. */
export async function siteName(site: Site): Promise<string> {
	const name = inline(await site.settings.string("site.name"));
	return name !== "" ? name : new URL(site.origin).host;
}

/** LlmsTxt::render: the site name, summary, notes, contacts, page-type sections, host sections, `## Optional`. */
export async function llmsTxt(site: Site, locale: string): Promise<string> {
	const fallback = await site.languages.defaultLocale();
	const blocks = [`# ${await siteName(site)}`];
	const summary = inline(
		localizedWithFallback(await site.settings.get("seo.llms_txt.summary"), locale, fallback) ||
			localizedWithFallback(await site.settings.get("seo.default_description"), locale, fallback),
	);
	if (summary !== "") {
		blocks.push(`> ${summary}`);
	}
	const intro = notesBlock(localizedWithFallback(await site.settings.get("seo.llms_txt.intro"), locale, fallback));
	if (intro !== "") {
		blocks.push(intro);
	}
	const contacts = await contactLines(site, locale, fallback);
	if (contacts.length > 0) {
		blocks.push(contacts.join("\n"));
	}
	const sections = await pageSections(site, locale);
	for (const section of site.config.llmsTxt?.sections ?? []) {
		const links = await section.links(locale, site);
		if (links.length > 0) {
			sections.push({ title: section.title(locale), links });
		}
	}
	const optional: LlmsTxtLink[] = site.config.sitemap === false ? [] : [{ name: t("optional.sitemap", locale), url: "/sitemap.xml", notes: t("optional.sitemap_notes", locale) }];
	for (const language of await site.languages.frontendActive()) {
		if (language.locale !== locale) {
			optional.push({ name: language.name, url: await llmsTxtPath(site, language.locale), notes: t("optional.language_notes", locale) });
		}
	}
	if (optional.length > 0) {
		sections.push({ title: "Optional", links: optional });
	}
	for (const section of sections) {
		const lines = section.links.map((link) => {
			const notes = inline(link.notes ?? "");
			return `- [${linkText(link.name)}](${linkUrl(site, link.url)})${notes !== "" ? `: ${notes}` : ""}`;
		});
		blocks.push([`## ${inline(section.title)}`, ...lines].join("\n"));
	}
	return `${blocks.join("\n\n")}\n`;
}

/** LlmsTxtUrl: `/llms.txt` for the default locale, `/uk/llms.txt` for another. */
export const llmsTxtPath = (site: Site, locale: string): Promise<string> => site.url("llms.txt", locale);

/** LlmsTxtController: 404 until turned on and while indexing is off; the default locale unless another is given. */
export async function llmsTxtResponse(site: Site, locale?: string): Promise<Response> {
	if (!(await llmsTxtEnabled(site)) || !(await indexingEnabled(site))) {
		return new Response(null, { status: 404 });
	}
	const body = await llmsTxt(site, locale ?? (await site.languages.defaultLocale()));
	return new Response(body, { headers: { "Content-Type": "text/plain; charset=utf-8" } });
}

/**
 * `/llms.txt`, or `/{locale}/llms.txt` for another frontend locale, as
 * SetLanguage routes it: the default locale's prefix 301s to `/llms.txt`;
 * any other path (an inactive locale, a page named llms.txt) is the host's.
 */
export async function localeLlmsTxt(site: Site, request: Request): Promise<Response | null> {
	const route = await routeLocale(site, new URL(request.url));
	if (route.type === "redirect") {
		return route.location === "/llms.txt" ? new Response(null, { status: 301, headers: { location: route.location } }) : null;
	}
	return route.path === "llms.txt" ? llmsTxtResponse(site, route.locale) : null;
}
