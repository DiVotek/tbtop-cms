import type { Seo } from "../seo/resolver";
import { gtmNoscriptHtml, seoTags } from "./head";

/**
 * The SEO head as React elements. React 19 hoists `<title>`, `<meta>` and
 * `<link>` into the document head wherever they render; a host with its own
 * head API (TanStack's `head()`, Next's metadata) uses `seoHead()` instead.
 */
export function CmsHead({ seo }: { seo: Seo }) {
	return (
		<>
			{seoTags(seo).map((tag, index) => {
				const key = `${tag.tag}-${index}`;
				if (tag.tag === "title") {
					return <title key={key}>{tag.children}</title>;
				}
				if (tag.tag === "script") {
					// JSON-LD is escaped by jsonLdText; the GTM loader is a fixed snippet around a checked id.
					return <script key={key} {...tag.attrs} dangerouslySetInnerHTML={{ __html: tag.children }} />;
				}
				const { hreflang, ...rest } = tag.attrs;
				const attrs = hreflang === undefined ? rest : { ...rest, hrefLang: hreflang };
				return tag.tag === "meta" ? <meta key={key} {...attrs} /> : <link key={key} {...attrs} />;
			})}
		</>
	);
}

/**
 * The `<noscript>` half of the Tag Manager install, for right after `<body>`
 * (InjectGtm puts it there in PHP): `<GtmNoscript id={seo.gtmContainerId} />`.
 * Renders nothing without a well-formed id.
 */
export function GtmNoscript({ id }: { id: string | null | undefined }) {
	if (gtmNoscriptHtml(id) === null) {
		return null;
	}
	return (
		<noscript>
			<iframe src={`https://www.googletagmanager.com/ns.html?id=${String(id)}`} height="0" width="0" style={{ display: "none", visibility: "hidden" }} />
		</noscript>
	);
}
