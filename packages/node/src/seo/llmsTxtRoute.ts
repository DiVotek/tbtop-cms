import { routeLocale } from "../frontend/load";
import type { Site } from "../site";
import { llmsTxtResponse } from "./llmsTxt";

/**
 * `/llms.txt`, or `/{locale}/llms.txt` for another frontend locale, as
 * SetLanguage routes it: the default locale's prefix 301s to `/llms.txt`
 * whatever the switches say (as every `/en/…` URL does; `/llms.txt` then
 * 404s while off); any other path (an inactive locale, a page named
 * llms.txt) is the host's.
 */
export async function localeLlmsTxt(site: Site, request: Request): Promise<Response | null> {
	const route = await routeLocale(site, new URL(request.url));
	if (route.type === "redirect") {
		return route.location === "/llms.txt" ? new Response(null, { status: 301, headers: { location: route.location } }) : null;
	}
	return route.path === "llms.txt" ? llmsTxtResponse(site, route.locale) : null;
}
