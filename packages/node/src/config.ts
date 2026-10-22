import type { Kysely } from "kysely";
import type { BlockRegistry } from "./blocks/registry";
import type { CmsDatabase } from "./db/schema";
import type { PageTypeDef } from "./content/pageTypes";
import type { SchemaTypeDef } from "./seo/schemaTypes";

/** What a media reference (an upload path or URL) resolves to; width/height/alt feed og:image. */
export type MediaDetails = { url: string; alt?: string | null; mime?: string | null; width?: number | null; height?: number | null };

export type CmsConfig = {
	/** The host's Kysely instance over a database the package migrations ran on. */
	db: Kysely<CmsDatabase>;
	/** The block kinds: `implementBlocks({ ...cmsBlocks, ...mine }, { ...cmsBlockImpls, ...myImpls })`. */
	blocks: BlockRegistry;
	/** Extra page types next to the built-in page, category and article. */
	pageTypes?: PageTypeDef[];
	/** Extra JSON-LD schema types next to the built-in WebPage family, Article family and CollectionPage. */
	schemaTypes?: SchemaTypeDef[];
	/** The public site origin (`https://example.com`); default: the request's origin. */
	url?: string;
	/** A media reference to its public URL; default: absolute URLs and `/…` paths as is, other paths under `/storage/`. */
	media?: (ref: string) => MediaDetails | null;
	/** Settings keys holding secrets; never sent to the frontend. */
	secretSettings?: string[];
	/** Paths robots.txt disallows besides the admin prefix and `/login`. */
	robotsDisallow?: string[];
	/** The admin URL prefix, disallowed in robots.txt; default `/admin`. */
	adminPrefix?: string;
	/** Pages per sitemap chunk before the locale split; default 1000 (`seo.sitemap_per_chunk` overrides it). */
	sitemapPerChunk?: number;
	sitemap?: boolean;
	/** The json-render root element kind; default `Fragment`. */
	rootKind?: string;
	/** Throw on a broken block row instead of skipping it (`tbtop-cms.blocks.strict`). */
	strictBlocks?: boolean;
	/** Can this request see drafts (preview)? Default: never. */
	canPreview?: (request: Request) => boolean | Promise<boolean>;
	/** Extra frontend strings per locale, over the package's own. */
	strings?: Record<string, Record<string, string>>;
	debug?: boolean;
};

const MIME: Record<string, string> = {
	jpg: "image/jpeg",
	jpeg: "image/jpeg",
	png: "image/png",
	webp: "image/webp",
	avif: "image/avif",
	gif: "image/gif",
	svg: "image/svg+xml",
};

export function defaultMedia(ref: string): MediaDetails | null {
	if (ref.trim() === "") {
		return null;
	}
	const url = /^(https?:)?\/\//.test(ref) || ref.startsWith("/") ? ref : `/storage/${ref}`;
	const ext = /\.([a-z0-9]+)(?:\?|#|$)/i.exec(ref)?.[1]?.toLowerCase();
	return { url, mime: ext === undefined ? null : (MIME[ext] ?? null) };
}
