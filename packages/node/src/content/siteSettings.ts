import { S } from "@tbtop/admin";
import { resolveForm } from "../blocks/fields";
import { flattenLocales, translatablePaths } from "../blocks/locale";
import type { Site } from "../site";
import { tabFields } from "./hostSettings";

/** The package's own Site settings in the settings object. */
export type BuiltinSettings = {
	name: string | null;
	/** The logo's URL. */
	logo: string | null;
	/** The favicon's URL. */
	favicon: string | null;
};

/** The settings object without the host's types: the built-in keys, then each host field by name. */
export type CmsSettings = BuiltinSettings & Record<string, unknown>;

/**
 * The Site settings as one object in `locale`: the built-in keys, then the
 * host tabs' fields by name, resolved as block fields are (locale maps to
 * the locale, uploads to URLs, links and pages to targets) and parsed by
 * `siteSettings.schema`. Secret keys are left out. Its rows come through
 * the content cache; the links in it are resolved per request.
 */
export async function settingsObject(site: Site, locale: string): Promise<CmsSettings> {
	const settings = site.settings;
	const [name, logo, favicon] = await Promise.all([settings.get("site.name"), settings.get("site.logo"), settings.get("site.favicon")]);
	const builtin: BuiltinSettings = { name: typeof name === "string" ? name : null, logo: media(site, logo), favicon: media(site, favicon) };
	const hostTabs = site.config.siteSettings;
	if (hostTabs === undefined) {
		return builtin;
	}
	const fields = tabFields(hostTabs.tabs(new S()).flatMap((tab) => tab.children)).filter((field) => !settings.isSecret(`site.${field.name}`));
	const stored = Object.fromEntries(await Promise.all(fields.map(async (field) => [field.name, await settings.get(`site.${field.name}`)] as const)));
	const [fallback, locales] = await Promise.all([site.languages.defaultLocale(), site.languages.frontendActive()]);
	const flat = flattenLocales(
		stored,
		locale,
		fallback,
		locales.map((row) => row.locale),
		translatablePaths(fields),
	);
	const resolved = await resolveForm(fields, flat, await site.resolveContext(locale, null));
	return { ...parsed(hostTabs.schema, resolved), ...builtin };
}

const media = (site: Site, value: unknown): string | null => (typeof value === "string" || typeof value === "number" ? site.mediaUrl(String(value)) : null);

/** A value that fails the schema ships as resolved, with a warning: settings saved before a field changed type. */
function parsed(schema: NonNullable<Site["config"]["siteSettings"]>["schema"], resolved: Record<string, unknown>): Record<string, unknown> {
	if (schema === undefined) {
		return resolved;
	}
	const result = schema.safeParse(resolved);
	if (result.success) {
		return result.data;
	}
	console.warn("settings.invalid", { error: result.error.message });
	return resolved;
}
