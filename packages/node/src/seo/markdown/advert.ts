import type { Site } from "../../site";
import { llmsTxtEnabled } from "../llmsTxt";
import { indexingEnabled } from "../resolver";
import { markdownEnabled, markdownPath } from "./context";

/**
 * The HTML answer's headers while markdown is on: `Vary: Accept`, and `Link`
 * to the `.md` twin and llms.txt (not for a preview).
 */
export async function markdownAdvertHeaders(site: Site, path: string, locale: string, isPreview: boolean): Promise<Record<string, string>> {
	if (!(await markdownEnabled(site)) || !(await indexingEnabled(site))) {
		return {};
	}
	if (isPreview) {
		return { Vary: "Accept" };
	}
	const links = [`<${site.absolute(await markdownPath(site, path, locale))}>; rel="alternate"; type="text/markdown"`];
	if (await llmsTxtEnabled(site)) {
		links.push(`<${site.absolute("/llms.txt")}>; rel="describedby"; type="text/plain"`);
	}
	return { Vary: "Accept", Link: links.join(", ") };
}
