import type { Site } from "../site";
import { indexingEnabled } from "./resolver";

/** Directives the CMS writes itself (RobotsTxt::OWNED_DIRECTIVES). */
export const OWNED = ["user-agent", "allow", "disallow", "sitemap", "content-signal"];

/** ContentSignals::SIGNALS: name, settings key, default. */
const SIGNALS: [string, string, boolean][] = [
	["search", "seo.content_signals.search", true],
	["ai-input", "seo.content_signals.ai_input", true],
	["ai-train", "seo.content_signals.ai_train", false],
];

/** ContentSignals::line: `Content-Signal: search=yes, ai-input=yes, ai-train=no`, or null until an editor opts in. */
export async function contentSignalLine(site: Site): Promise<string | null> {
	if (!Boolean(await site.settings.get("seo.content_signals.enabled", false))) {
		return null;
	}
	const parts: string[] = [];
	for (const [name, key, fallback] of SIGNALS) {
		const value = await site.settings.get(key);
		parts.push(`${name}=${(value === null || value === undefined ? fallback : Boolean(value)) ? "yes" : "no"}`);
	}
	return `Content-Signal: ${parts.join(", ")}`;
}

const normalize = (path: string): string => {
	const trimmed = path.trim().replace(/^\/+|\/+$/g, "");
	return trimmed === "" ? "" : `/${trimmed}`;
};

/** RobotsTxt: the package's group, the editor's extra lines (owned directives dropped), the sitemap. */
export async function robotsTxt(site: Site): Promise<string> {
	const enabled = await indexingEnabled(site);
	const disallow = [...new Set([site.config.adminPrefix ?? "admin", "login", ...(site.config.robotsDisallow ?? [])].map(normalize))].filter(
		(path) => path !== "",
	);
	const signal = enabled ? await contentSignalLine(site) : null;
	const group = enabled
		? ["User-agent: *", "Allow: /", ...disallow.map((path) => `Disallow: ${path}`), ...(signal === null ? [] : [signal])]
		: ["User-agent: *", "Disallow: /"];
	const custom = customLines(await site.settings.string("seo.robots_extra"));
	const sitemap = enabled && site.config.sitemap !== false ? ["", `Sitemap: ${site.absolute("/sitemap.xml")}`] : [];
	return [...group, ...custom, ...sitemap, ""].join("\n");
}

export function customLines(raw: string): string[] {
	return raw
		.trim()
		.split(/\r\n|\r|\n/)
		.map((line) => line.trimEnd())
		.filter((line) => line.trim() !== "")
		.filter((line) => line.trim().startsWith("#") || !OWNED.includes((line.split(":", 1)[0] ?? "").trim().toLowerCase()));
}
