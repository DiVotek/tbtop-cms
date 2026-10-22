import type { Site } from "../site";
import { indexingEnabled } from "./resolver";

/** Directives the CMS writes itself (RobotsTxt::OWNED_DIRECTIVES). */
export const OWNED = ["user-agent", "allow", "disallow", "sitemap"];

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
	const group = enabled ? ["User-agent: *", "Allow: /", ...disallow.map((path) => `Disallow: ${path}`)] : ["User-agent: *", "Disallow: /"];
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
