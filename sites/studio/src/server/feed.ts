import { site } from "./cms";
import { pageText } from "./pageText";

/** Site-local RSS (CMS has no feed route). */
export async function rssXml(requestUrl: string): Promise<string> {
	const { cms } = await site();
	const base = new URL(requestUrl).origin;
	const settings = await cms.site().settings.forFrontend();
	const siteName = String(settings["site.name"] ?? "Lumen Studio");
	const categories = await cms.site().pages.query().selectAll().where("type", "=", "category").execute();
	const blog = categories[0];
	if (blog === undefined) {
		return `<?xml version="1.0"?><rss version="2.0"><channel><title>${escapeXml(siteName)}</title></channel></rss>`;
	}
	const locale = await cms.site().languages.defaultLocale();
	const fallback = locale;
	const children = await cms.site().pages.children(blog.id, { live: true, indexable: true, limit: 20 });
	const items = await Promise.all(
		children.map(async (child) => {
			const link = `${base}${await cms.site().pageUrl(child, locale)}`;
			const title = pageText(child, "name", locale, fallback);
			const description = pageText(child, "summary", locale, fallback);
			return `<item><title>${escapeXml(title)}</title><link>${escapeXml(link)}</link><description>${escapeXml(description)}</description><pubDate>${child.publishedAt?.toUTCString() ?? ""}</pubDate><guid>${escapeXml(link)}</guid></item>`;
		}),
	);
	const description = typeof settings["seo.default_description"] === "object" ? String((settings["seo.default_description"] as Record<string, string>).en ?? "") : String(settings["seo.default_description"] ?? "");
	return `<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>${escapeXml(siteName)} Journal</title><link>${base}/journal</link><description>${escapeXml(description)}</description>${items.join("")}</channel></rss>`;
}

function escapeXml(value: string): string {
	return value.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
