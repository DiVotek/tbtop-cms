import { definePage, Effects, S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { OFFICES, SOCIALS } from "../content/builtInCollections";
import { tabFields } from "../content/hostSettings";
import { BUSINESS_SUBTYPES } from "../seo/identity";
import { extractGtmId, verification } from "../seo/resolver";
import { OWNED } from "../seo/robots";
import type { Site } from "../site";
import type { Dict } from "../support/values";
import { collectionListUrl } from "./linkFields";
import { adminPrefix, editFooter, listHeader, NAV, siteOf } from "./support";

/** A settings page (SettingsPageBase): form fields mapped to dotted settings keys. */
function settingsPage(
	cms: Cms,
	options: {
		path: string;
		title: string;
		subtitle: string;
		nav: { label: string; icon: string; order: number };
		map: Record<string, string>;
		fields: (s: S) => unknown[];
		defaults?: Dict;
		/** A save-time check that may rewrite the form (normalizeForm + validateForm): an error message rejects the whole save. */
		prepare?: (form: Dict, site: Site) => string | null | Promise<string | null>;
	},
) {
	return definePage({
		path: options.path,
		...listHeader("settings", options.title, options.subtitle),
		nav: { group: NAV.settings, ...options.nav },
		view: async (s, { request }) => {
			const settings = cms.site(request).settings;
			const record: Dict = { ...options.defaults };
			for (const [field, key] of Object.entries(options.map)) {
				const value = await settings.get(key);
				if (value !== null) {
					record[field] = value;
				}
			}
			return s
				.form(options.path.replaceAll("/", "_"), [
					...options.fields(s),
					// No list to go back to: Cancel reloads this page (SettingsPageBase).
					editFooter(s, `${adminPrefix(cms)}/${options.path}`, s.action("save").label("Save").color("primary").keybinding("mod+s").submit()),
				])
				.record(record)
				.onSubmit(async (ctx) => {
					const site = siteOf(cms, ctx);
					const form = { ...(ctx.form as Dict) };
					const rejected = (await options.prepare?.(form, site)) ?? null;
					if (rejected !== null) {
						return Effects.make().notify(rejected, "error");
					}
					for (const [field, key] of Object.entries(options.map)) {
						if (!(field in form)) {
							continue;
						}
						const value = normalize(form[field]);
						if (value === null) {
							await site.settings.forget(key);
						} else {
							await site.settings.set(key, value);
						}
					}
					return Effects.make().notify("Settings saved");
				});
		},
	});
}

/** Blank strings and rows that are entirely blank are not stored. */
function normalize(value: unknown): unknown {
	if (typeof value === "string") {
		return value.trim() === "" ? null : value;
	}
	if (Array.isArray(value)) {
		const rows = value.filter(
			(row) =>
				!(typeof row === "object" && row !== null && Object.values(row).every((v) => v === null || v === "" || (Array.isArray(v) && v.length === 0))),
		);
		return rows.length === 0 ? null : rows;
	}
	return value ?? null;
}

const SITE_MAP: Record<string, string> = {
	site_name: "site.name",
	site_logo: "site.logo",
	site_favicon: "site.favicon",
};

/** The host tabs' fields by name, each at `site.<name>`; a name the page already uses (or its key) throws. */
function hostSiteMap(cms: Cms): Record<string, string> {
	const tabs = cms.config.siteSettings?.tabs(new S()) ?? [];
	const taken = new Set([...Object.keys(SITE_MAP), ...Object.values(SITE_MAP)]);
	const map: Record<string, string> = {};
	for (const field of tabFields(tabs.flatMap((tab) => tab.children))) {
		const key = `site.${field.name}`;
		if (taken.has(field.name) || taken.has(key) || field.name in map) {
			throw new Error(`siteSettings: the field "${field.name}" is already on the Site settings page.`);
		}
		map[field.name] = key;
	}
	return map;
}

export function siteSettings(cms: Cms) {
	return settingsPage(cms, {
		path: "settings/site",
		title: "Site settings",
		subtitle: "General site identity: name, logo, and favicon.",
		nav: { label: "Site settings", icon: "settings", order: 20 },
		map: { ...SITE_MAP, ...hostSiteMap(cms) },
		fields: (s) => [
			s.tabs([
				{
					label: "Branding",
					children: [
						s.text("site_name").label("Site name").required().rules("max:255"),
						s.grid({ cols: 2 }, [
							s.upload("site_logo").label("Logo").accept("image/*"),
							s.upload("site_favicon").label("Favicon").accept("image/*"),
						]),
					],
				},
				{
					label: "Contacts",
					children: [
						s
							.displayText(
								"Phones, emails, the address and opening hours belong to an office; the first published office is the main one. Social links are their own list.",
							)
							.variant("muted"),
						s.flex(
							[
								s.action("openOffices").label("Offices").icon("map-pin").url(collectionListUrl(cms, OFFICES)),
								s.action("openSocials").label("Social links").icon("share-2").url(collectionListUrl(cms, SOCIALS)),
							],
							{ align: "center" },
						),
					],
				},
				...(cms.config.siteSettings?.tabs(s) ?? []),
			]),
		],
	});
}

const TWITTER_CARDS = [
	{ value: "auto", label: "Automatic" },
	{ value: "summary", label: "Summary" },
	{ value: "summary_large_image", label: "Large image" },
];

const IDENTITY = [
	{ value: "none", label: "None" },
	{ value: "person", label: "Person" },
	{ value: "organization", label: "Organization" },
	{ value: "online_store", label: "Online store" },
	{ value: "local_business", label: "Local business" },
];


/** Per-type template fields, keyed as the resolver reads them (SeoSettingsPage::typeTemplateMap). */
function typeTemplateMap(cms: Cms): Record<string, string> {
	const map: Record<string, string> = {};
	for (const type of cms.pageTypes.all()) {
		for (const slot of ["title", "description"]) {
			map[`seo_type_${type.key}_${slot}`] = `seo.type_templates.${type.key}.${slot}`;
		}
	}
	return map;
}

/** SeoSettingsPage::guardRobotsExtra: a line claiming a directive the CMS writes is refused. */
function robotsExtraError(value: unknown): string | null {
	if (typeof value !== "string") {
		return null;
	}
	for (const line of value.split(/\r\n|\n|\r/)) {
		const trimmed = line.trim();
		if (trimmed === "" || trimmed.startsWith("#")) {
			continue;
		}
		if (OWNED.includes((line.split(":", 1)[0] ?? "").trim().toLowerCase())) {
			return `The line "${trimmed}" sets a directive this site writes itself. Remove it — User-agent, Allow, Disallow and Sitemap come from the indexing switch and the sitemap setting.`;
		}
	}
	return null;
}

/** SeoSettingsPage::guardVerification: stores the bare token; input that yields none is refused. */
function verificationToken(form: Dict, field: string, metaName: string): string | null {
	const raw = form[field];
	if (typeof raw !== "string" || raw.trim() === "") {
		return null;
	}
	const token = verification(raw, metaName);
	if (token === null) {
		return "This does not look like a verification token or the matching meta tag. Paste the value the search console shows for this exact method — a tag for a different search engine will not work here.";
	}
	form[field] = token;
	return null;
}

/** SeoSettingsPage::normalizeForm: refuses owned robots lines and bad verification tokens. */
async function prepareSeo(form: Dict): Promise<string | null> {
	const rejected =
		robotsExtraError(form.seo_robots_extra) ??
		verificationToken(form, "seo_verification_google", "google-site-verification") ??
		verificationToken(form, "seo_verification_bing", "msvalidate.01");
	if (rejected !== null) {
		return rejected;
	}
	// Stored as the bare id, so the reopened page shows it instead of the pasted snippet.
	if (typeof form.seo_gtm_container_id === "string") {
		form.seo_gtm_container_id = extractGtmId(form.seo_gtm_container_id);
	}
	return null;
}

export function seoSettings(cms: Cms) {
	return settingsPage(cms, {
		path: "settings/seo",
		title: "SEO settings",
		subtitle: "Default metadata used across the site unless a page overrides it.",
		nav: { label: "SEO settings", icon: "search", order: 30 },
		defaults: { seo_indexing_enabled: true, seo_twitter_card: "auto", seo_identity_type: "none", seo_not_found_enabled: true },
		map: {
			seo_indexing_enabled: "seo.indexing_enabled",
			seo_sitemap_per_chunk: "seo.sitemap_per_chunk",
			seo_robots_extra: "seo.robots_extra",
			seo_title_template: "seo.title_template",
			seo_description_template: "seo.description_template",
			seo_title_separator: "seo.title_separator",
			seo_title_suffix: "seo.title_suffix",
			seo_default_description: "seo.default_description",
			seo_default_og_image: "seo.default_og_image",
			seo_twitter_card: "seo.twitter_card",
			seo_identity_type: "seo.identity.type",
			seo_identity_name: "seo.identity.name",
			seo_identity_legal_name: "seo.identity.legal_name",
			seo_identity_logo: "seo.identity.logo",
			seo_identity_subtype: "seo.identity.subtype",
			seo_identity_subtype_custom: "seo.identity.subtype_custom",
			seo_verification_google: "seo.verification.google",
			seo_verification_bing: "seo.verification.bing",
			seo_gtm_container_id: "seo.gtm_container_id",
			seo_not_found_enabled: "seo.not_found.enabled",
			seo_not_found_retention_days: "seo.not_found.retention_days",
			...typeTemplateMap(cms),
		},
		prepare: prepareSeo,
		fields: (s) => [
			s.tabs([
				{
					label: "Indexing",
					children: [
						s.boolean("seo_indexing_enabled").label("Let search engines index the site"),
						s.number("seo_sitemap_per_chunk").label("Sitemap URLs per file").rules("nullable|integer|min:1|max:50000"),
						s.textarea("seo_robots_extra").label("Extra robots.txt lines").helperText("User-agent, Allow, Disallow and Sitemap lines are managed by the CMS and ignored here."),
					],
				},
				{
					label: "Titles",
					children: [
						s.text("seo_title_template").label("Title template").translatable().helperText("{title} {sep} {site_name} {parent} {date}"),
						s.text("seo_description_template").label("Description template").translatable(),
						s.text("seo_title_separator").label("Separator").translatable(),
						s.text("seo_title_suffix").label("Title suffix").helperText("Appended when there is no template."),
						s.textarea("seo_default_description").label("Default description").translatable(),
					],
				},
				{
					label: "Page types",
					children: [
						s
							.displayText(
								'A type\'s template overrides the site-wide one. An empty field means "same as the site", not an empty title. {parent} is the parent category\'s name and {date} the publication date.',
							)
							.variant("muted"),
						...cms.pageTypes
							.all()
							.flatMap((type) => [
								s.text(`seo_type_${type.key}_title`).label(`Title: ${type.label}`).translatable().rules("nullable|max:255"),
								s.text(`seo_type_${type.key}_description`).label(`Description: ${type.label}`).translatable().rules("nullable|max:500"),
							]),
					],
				},
				{
					label: "Social",
					children: [
						s.upload("seo_default_og_image").label("Default social image").accept("image/*"),
						s.select("seo_twitter_card").label("Twitter card").options(TWITTER_CARDS),
					],
				},
				{
					label: "Identity",
					children: [
						s.select("seo_identity_type").label("The site is").options(IDENTITY),
						s.text("seo_identity_name").label("Name").helperText("Defaults to the site name."),
						s.text("seo_identity_legal_name").label("Legal name"),
						s.upload("seo_identity_logo").label("Logo").accept("image/*"),
						s
							.select("seo_identity_subtype")
							.label("Business type")
							.options([...BUSINESS_SUBTYPES.map((value) => ({ value, label: value })), { value: "other", label: "Other" }]),
						s.text("seo_identity_subtype_custom").label("Other business type").regex("/^[A-Z][A-Za-z]+$/"),
						s
							.displayText("The address, phones, emails, opening hours and coordinates come from the main office (Settings → Offices); the other offices are published as its departments.")
							.variant("muted"),
					],
				},
				{
					label: "Integrations",
					children: [
						s
							.text("seo_gtm_container_id")
							.label("Google Tag Manager container ID")
							.placeholder("GTM-XXXXXXX")
							.helperText(
								"Paste the container ID (GTM-XXXXXXX) or the full install code from Google Tag Manager — either works. Leave empty and nothing is inserted. Internal navigation does not reload the page: count page views with a History Change trigger in GTM.",
							)
							.rules("nullable|max:2000"),
						s.text("seo_verification_google").label("Google Search Console"),
						s.text("seo_verification_bing").label("Bing Webmaster Tools"),
					],
				},
				{
					label: "404 log",
					children: [
						s.boolean("seo_not_found_enabled").label("Count 404s").helperText("Misses on the site go to the 404 log; scanner probes are left out."),
						s
							.number("seo_not_found_retention_days")
							.label("Keep entries for (days)")
							.rules("nullable|integer|min:1|max:3650")
							.helperText("Entries not seen for this long are pruned (default 90)."),
					],
				},
			]),
		],
	});
}
