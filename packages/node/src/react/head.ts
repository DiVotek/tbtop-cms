import type { ErrorSeo } from "../frontend/errorPage";
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

const GTM_ID = /^GTM-[A-Z0-9]+$/;

/**
 * Google's stock Tag Manager loader, verbatim but for the id (InjectGtm::headScript).
 * The id is checked again here: it lands inside a script.
 */
export function gtmScript(id: string | null | undefined): string | null {
	if (id === null || id === undefined || !GTM_ID.test(id)) {
		return null;
	}
	return `(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','${id}');`;
}

/** The `<noscript>` half for right after `<body>` (InjectGtm::bodyNoscript), as HTML for non-React hosts. */
export function gtmNoscriptHtml(id: string | null | undefined): string | null {
	if (id === null || id === undefined || !GTM_ID.test(id)) {
		return null;
	}
	return `<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=${id}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>`;
}

const meta = (key: "name" | "property", name: string, content: string | null | undefined): HeadTag[] =>
	content === null || content === undefined || content === "" ? [] : [{ tag: "meta", attrs: { [key]: name, content } }];

/** The `seo` prop as head tags, in the order and with the conditions of the PHP head (head.blade.php / CmsHead). */
export function seoTags(seo: Seo | ErrorSeo): HeadTag[] {
	const showAlternates = seo.alternates.length > 1;
	const image = seo.ogImage;
	const article = seo.ogType === "article";
	const gtm = gtmScript(seo.gtmContainerId);
	return [
		// Google asks for the loader as high in the head as possible.
		...(gtm === null ? [] : [{ tag: "script", attrs: {}, children: gtm } as HeadTag]),
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
 * the title as `{ title }` in `meta`. JSON-LD is already escaped; the GTM
 * loader, when set, is the first script and has no `type`.
 */
export function seoHead(seo: Seo | ErrorSeo): {
	meta: Record<string, string>[];
	links: Record<string, string>[];
	scripts: { type?: string; children: string }[];
} {
	const out = { meta: [] as Record<string, string>[], links: [] as Record<string, string>[], scripts: [] as { type?: string; children: string }[] };
	for (const tag of seoTags(seo)) {
		if (tag.tag === "title") {
			out.meta.push({ title: tag.children });
		} else if (tag.tag === "meta") {
			out.meta.push(tag.attrs);
		} else if (tag.tag === "link") {
			out.links.push(reactAttrs(tag.attrs));
		} else {
			out.scripts.push(tag.attrs.type === undefined ? { children: tag.children } : { type: tag.attrs.type, children: tag.children });
		}
	}
	return out;
}
