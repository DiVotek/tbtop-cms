import type { Seo } from "../seo/resolver";
import { seoTags } from "./head";

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
					// Escaped by jsonLdText: no `<` reaches the HTML.
					return <script key={key} {...tag.attrs} dangerouslySetInnerHTML={{ __html: tag.children }} />;
				}
				const { hreflang, ...rest } = tag.attrs;
				const attrs = hreflang === undefined ? rest : { ...rest, hrefLang: hreflang };
				return tag.tag === "meta" ? <meta key={key} {...attrs} /> : <link key={key} {...attrs} />;
			})}
		</>
	);
}
