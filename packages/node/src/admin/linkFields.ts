import { definePage, type HostRule } from "@tbtop/admin";
import { type CmsFieldBinding, LINK_SHAPE_RULE, PAGE_REF_RULE } from "../blocks/cmsFields";
import type { Cms } from "../cms";
import { linkShape } from "../content/link";
import { pageExists, pageId, pageNameOf, searchPages } from "../content/pageRefs";
import type { Site } from "../site";
import { adminPrefix } from "./support";

const SEARCH_PATH = "link/page-search";
const SEARCH_FIELD = "page";
/** RelationSearchLimit::DEFAULT_RESULTS. */
const SEARCH_LIMIT = 50;

/** The page search the link field's page picker posts `{search}` / `{value}` to (LinkPageSearchRoute). */
export const linkSearchEndpoint = (cms: Cms): string => `${adminPrefix(cms)}/${SEARCH_PATH}/select-options/${SEARCH_FIELD}`;

export const fieldBinding = (cms: Cms): CmsFieldBinding => ({ site: cms.site(), searchEndpoint: linkSearchEndpoint(cms) });

/** EditorLocales::list: the default language first, then the admin-active ones. */
async function editorLocales(site: Site): Promise<string[]> {
	const fallback = await site.languages.defaultLocale();
	return [...new Set([fallback, ...(await site.languages.adminActive()).map((row) => row.locale)])];
}

/**
 * The validation rules the link and pages fields declare, for
 * createAdmin({ hostRules }): `link_shape` (LinkShapeValidator) and
 * `page_ref` (PageRefRule) both look pages up in the CMS database.
 */
export function cmsHostRules(cms: Cms): Record<string, HostRule> {
	return {
		[LINK_SHAPE_RULE]: {
			message: "This link is not valid.",
			check: async (value, params) => {
				const site = cms.site();
				const { ok, link } = linkShape(value, params, await editorLocales(site));
				if (!ok || link === null || link.type !== "page") {
					return ok;
				}
				return pageExists(site, Number(link.page));
			},
		},
		[PAGE_REF_RULE]: {
			message: "This page cannot be referenced here.",
			check: async (value, params) => {
				const except = params.find((param) => param.startsWith("except="));
				const id = pageId(value);
				// Drafts and trashed pages pass: the site drops non-live pages from the list, a retyped target must not lock the page out of saving.
				return id !== null && id !== (except === undefined ? null : pageId(except.slice("except=".length))) && pageExists(cms.site(), id, { withTrashed: true });
			},
		},
	};
}

/**
 * LinkPageSearchController as an admin page, so the panel's own user check
 * guards it: the link field posts to its select-options endpoint. Any page,
 * drafts included (an editor links to a page about to be published), named
 * in the default language. GET shows the same search as a plain picker.
 */
export function linkPageSearch(cms: Cms) {
	return definePage({
		path: SEARCH_PATH,
		title: "Page search",
		mcp: false,
		view: (s) => {
			const site = cms.site();
			const option = async (page: { id: number; name: Record<string, unknown> }) => {
				const fallback = await site.languages.defaultLocale();
				return { value: String(page.id), label: pageNameOf(page, fallback, fallback) ?? "" };
			};
			const field = s
				.select(SEARCH_FIELD)
				.label("Page")
				.searchable()
				.query(async (_deps, search) => {
					const pages = await searchPages(site, { term: search, locales: [await site.languages.defaultLocale()], limit: SEARCH_LIMIT });
					return Promise.all(pages.map(option));
				})
				.resolveUsing(async (value) => {
					const id = pageId(value);
					const page = id === null ? null : await site.pages.find(id);
					return page === null ? null : (await option(page)).label;
				});
			return s.form("linkPageSearch", [field]);
		},
	});
}
