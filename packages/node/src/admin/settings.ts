import { definePage, Effects, type S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { BUSINESS_SUBTYPES } from "../seo/identity";
import type { Dict } from "../support/values";
import { NAV, siteOf } from "./support";

/** A settings page (SettingsPageBase): form fields mapped to dotted settings keys. */
function settingsPage(
	cms: Cms,
	options: {
		path: string;
		title: string;
		nav: { label: string; icon: string; order: number };
		map: Record<string, string>;
		fields: (s: S) => unknown[];
		defaults?: Dict;
	},
) {
	return definePage({
		path: options.path,
		title: options.title,
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
					s.actionsRow([s.action("save").label("Save").color("primary").keybinding("mod+s").submit()]),
				])
				.record(record)
				.onSubmit(async (ctx) => {
					const site = siteOf(cms, ctx);
					const form = ctx.form as Dict;
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

export function siteSettings(cms: Cms) {
	return settingsPage(cms, {
		path: "settings/site",
		title: "Site",
		nav: { label: "Site", icon: "settings", order: 20 },
		map: {
			site_name: "site.name",
			site_logo: "site.logo",
			site_favicon: "site.favicon",
			phones: "site.contacts.phones",
			emails: "site.contacts.emails",
			socials: "site.contacts.socials",
		},
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
						s.repeater("phones").label("Phones").fields([s.text("number").label("Number").placeholder("+380 44 000 00 00").rules("nullable|max:40")]),
						s.repeater("emails").label("Emails").fields([s.text("address").label("Address").rules("nullable|email|max:190")]),
						s
							.repeater("socials")
							.label("Social profiles")
							.fields([
								s.text("label").label("Short label").placeholder("TG").rules("nullable|max:8"),
								s.text("name").label("Name").placeholder("Telegram").rules("nullable|max:40"),
								s.text("href").label("Link").rules("nullable|url|max:255"),
							]),
					],
				},
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

const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];

export function seoSettings(cms: Cms) {
	return settingsPage(cms, {
		path: "settings/seo",
		title: "SEO",
		nav: { label: "SEO", icon: "search", order: 30 },
		defaults: { seo_indexing_enabled: true, seo_twitter_card: "auto", seo_identity_type: "none" },
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
			seo_identity_street: "seo.identity.address.street",
			seo_identity_locality: "seo.identity.address.locality",
			seo_identity_region: "seo.identity.address.region",
			seo_identity_postal_code: "seo.identity.address.postal_code",
			seo_identity_country: "seo.identity.address.country",
			seo_identity_latitude: "seo.identity.geo.latitude",
			seo_identity_longitude: "seo.identity.geo.longitude",
			seo_identity_opening_hours: "seo.identity.opening_hours",
			seo_verification_google: "seo.verification.google",
			seo_verification_bing: "seo.verification.bing",
		},
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
						s.text("seo_identity_street").label("Street").translatable(),
						s.text("seo_identity_locality").label("City").translatable(),
						s.text("seo_identity_region").label("Region").translatable(),
						s.text("seo_identity_postal_code").label("Postal code"),
						s.text("seo_identity_country").label("Country (ISO code)").rules("nullable|size:2"),
						s.number("seo_identity_latitude").label("Latitude").rules("nullable|numeric|between:-90,90"),
						s.number("seo_identity_longitude").label("Longitude").rules("nullable|numeric|between:-180,180"),
						s
							.repeater("seo_identity_opening_hours")
							.label("Opening hours")
							.fields([
								s
									.select("days")
									.label("Days")
									.multiple()
									.options(DAYS.map((day) => ({ value: day, label: day }))),
								s.time("opens").label("Opens"),
								s.time("closes").label("Closes"),
							]),
					],
				},
				{
					label: "Verification",
					children: [
						s.text("seo_verification_google").label("Google Search Console"),
						s.text("seo_verification_bing").label("Bing Webmaster Tools"),
					],
				},
			]),
		],
	});
}
