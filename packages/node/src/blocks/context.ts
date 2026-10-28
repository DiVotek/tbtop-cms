import type { MediaImage } from "../config";
import type { ResolvedLink } from "../content/link";
import type { Page, TranslatableField } from "../content/page";
import type { PageRef } from "../content/pageRefs";
import type { PageColumns } from "../frontend/pageView";
import type { CmsSettings } from "../content/siteSettings";
import type { Site } from "../site";

/**
 * A pages-field ref with its page's own columns (as `site.pageViews` gives
 * them) and resolved custom fields (`ctx.pages(value, { fields: true })`).
 */
export type PageRefWithFields = PageRef & Omit<PageColumns, "id" | "name"> & { fields: Record<string, unknown> };

/** What a block's or page type's resolve() reads besides its own input (ResolveContext.php). */
export type ResolveContext = {
	locale: string;
	fallbackLocale: string;
	/** The page being rendered; null for header/footer layout blocks. */
	page: Page | null;
	/** The page type's resolved custom fields. */
	fields: Record<string, unknown>;
	mediaUrl(ref: string): string | null;
	/** The image with its srcset (config `imageLoader`), width, height and alt; null like mediaUrl. */
	mediaImage(ref: string): MediaImage | null;
	richtextHtml(value: unknown): string;
	link(value: unknown): Promise<ResolvedLink | null>;
	/** A pages field value to its live pages, `{ id, name, url }` in this locale (PageRefList). */
	pages(value: unknown): Promise<PageRef[]>;
	/**
	 * The same refs, each with its page's columns (summary, image, heading…)
	 * and resolved custom fields (what that page's own props carry). One resolve per page: a convenience, not a
	 * query plan. Inside those pages' resolvers it gives empty fields.
	 */
	pages(value: unknown, options: { fields: true }): Promise<PageRefWithFields[]>;
	/** The live pages whose page-type pages field `field` picks this page (Cms::referencedByLinks), only of type or subtype `from` when given. */
	referencedBy(field: string, from?: string): Promise<PageRef[]>;
	/** A translatable page column as text, locale then fallback. */
	pageText(field: TranslatableField): string | null;
	/** A package string (`forms.contact.heading`) in this locale. */
	t(key: string): string;
	/** The Site settings as one object in this locale; `defineSettings(...).of(ctx)` types the host's keys. */
	settings(): Promise<CmsSettings>;
	/** The request's site: settings, pages, pageViews and the rest, for what the helpers above do not cover. */
	site: Site;
};
