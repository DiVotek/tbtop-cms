import type { Seo } from "../seo/resolver";

/** One head element: a framework-neutral description a host maps onto its own head API. */
export type HeadTag =
	| { tag: "title"; children: string }
	| { tag: "meta"; attrs: Record<string, string> }
	| { tag: "link"; attrs: Record<string, string> }
	| { tag: "script"; attrs: Record<string, string>; children: string };

/**
 * The JSON with `<`, `>`, `&` escaped (JSON_HEX_TAG | JSON_HEX_AMP), so a
 * title holding `</script>` cannot leave the script element.
 */
export const jsonLdText = (node: unknown): string =>
	JSON.stringify(node).replace(/[<>&]/g, (char) => `\\u${char.charCodeAt(0).toString(16).padStart(4, "0")}`);

const meta = (key: "name" | "property", name: string, content: string | null | undefined): HeadTag[] =>
	content === null || content === undefined || content === "" ? [] : [{ tag: "meta", attrs: { [key]: name, content } }];

/** The `seo` prop as head tags, in the order and with the conditions of the PHP head (head.blade.php / CmsHead). */
export function seoTags(seo: Seo): HeadTag[] {
	const showAlternates = seo.alternates.length > 1;
	const image = seo.ogImage;
	const article = seo.ogType === "article";
	return [
		{ tag: "title", children: seo.title },
		...meta("name", "description", seo.description),
		...(seo.canonical ? [{ tag: "link", attrs: { rel: "canonical", href: seo.canonical } } as HeadTag] : []),
		...(seo.noindex ? meta("name", "robots", "noindex") : []),
		...(seo.favicon ? [{ tag: "link", attrs: { rel: "icon", href: seo.favicon } } as HeadTag] : []),
		...meta("name", "google-site-verification", seo.verificationGoogle),
		...meta("name", "msvalidate.01", seo.verificationBing),
		...(showAlternates
			? seo.alternates.map((alternate): HeadTag => ({ tag: "link", attrs: { rel: "alternate", hreflang: alternate.locale, href: alternate.url } }))
			: []),
		...(showAlternates && seo.xDefault ? [{ tag: "link", attrs: { rel: "alternate", hreflang: "x-default", href: seo.xDefault } } as HeadTag] : []),
		...meta("property", "og:type", seo.ogType),
		...meta("property", "og:url", seo.ogUrl),
		...meta("property", "og:site_name", seo.siteName),
		...meta("property", "og:locale", seo.ogLocale),
		...seo.ogLocaleAlternates.flatMap((locale) => meta("property", "og:locale:alternate", locale)),
		...meta("property", "og:title", seo.ogTitle),
		...meta("property", "og:description", seo.ogDescription),
		...meta("property", "og:image", image),
		...(image ? meta("property", "og:image:alt", seo.ogImageAlt) : []),
		...(image ? meta("property", "og:image:type", seo.ogImageType) : []),
		...(image && seo.ogImageWidth && seo.ogImageHeight
			? [...meta("property", "og:image:width", String(seo.ogImageWidth)), ...meta("property", "og:image:height", String(seo.ogImageHeight))]
			: []),
		...(article ? meta("property", "article:published_time", seo.articlePublishedTime) : []),
		...(article ? meta("property", "article:modified_time", seo.articleModifiedTime) : []),
		...(article ? meta("property", "article:author", seo.articleAuthor) : []),
		...meta("name", "twitter:card", seo.twitterCard),
		...meta("name", "twitter:title", seo.ogTitle),
		...meta("name", "twitter:description", seo.ogDescription),
		...meta("name", "twitter:image", image),
		...(image ? meta("name", "twitter:image:alt", seo.ogImageAlt) : []),
		...seo.jsonLd.map((node): HeadTag => ({ tag: "script", attrs: { type: "application/ld+json" }, children: jsonLdText(node) })),
	];
}

/** React's attribute spelling: TanStack's head() and React DOM take `hrefLang`. */
const reactAttrs = (attrs: Record<string, string>): Record<string, string> =>
	Object.fromEntries(Object.entries(attrs).map(([key, value]) => [key === "hreflang" ? "hrefLang" : key, value]));

/**
 * The `seo` prop in the shape of TanStack Router's `head()`: `{ meta, links, scripts }`,
 * the title as `{ title }` in `meta`. JSON-LD is already escaped.
 */
export function seoHead(seo: Seo): {
	meta: Record<string, string>[];
	links: Record<string, string>[];
	scripts: { type: string; children: string }[];
} {
	const out = { meta: [] as Record<string, string>[], links: [] as Record<string, string>[], scripts: [] as { type: string; children: string }[] };
	for (const tag of seoTags(seo)) {
		if (tag.tag === "title") {
			out.meta.push({ title: tag.children });
		} else if (tag.tag === "meta") {
			out.meta.push(tag.attrs);
		} else if (tag.tag === "link") {
			out.links.push(reactAttrs(tag.attrs));
		} else {
			out.scripts.push({ type: tag.attrs.type ?? "application/ld+json", children: tag.children });
		}
	}
	return out;
}
