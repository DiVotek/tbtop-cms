import type { Site } from "../../site";
import { llmsTxtEnabled, llmsTxtPath } from "../llmsTxt";
import { indexingEnabled } from "../resolver";
import { markdownEnabled, markdownPath } from "./context";

/**
 * The HTML answer's headers for agents (PageController::advertise): while
 * markdown is on, `Vary: Accept` and a `Link` to the `.md` twin; while
 * llms.txt is on, a `Link` to the locale's llms.txt. No `Link` for a preview.
 */
export async function agentHeaders(site: Site, path: string, locale: string, isPreview: boolean): Promise<Record<string, string>> {
	if (!(await indexingEnabled(site))) {
		return {};
	}
	const markdown = await markdownEnabled(site);
	const headers: Record<string, string> = markdown ? { Vary: "Accept" } : {};
	if (isPreview) {
		return headers;
	}
	const links = markdown ? [`<${site.absolute(await markdownPath(site, path, locale))}>; rel="alternate"; type="text/markdown"`] : [];
	if (site.config.llmsTxt?.enabled !== false && (await llmsTxtEnabled(site))) {
		links.push(`<${site.absolute(await llmsTxtPath(site, locale))}>; rel="describedby"; type="text/plain"`);
	}
	return links.length > 0 ? { ...headers, Link: links.join(", ") } : headers;
}
