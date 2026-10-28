import type { AdminContext, HostRule, NavGroupDef, PageDef } from "@tbtop/admin";
import type { Cms } from "../cms";
import { allows, gated } from "./abilities";
import { auditActivity, auditLog } from "./audit";
import { blocksShowcase } from "./blocks";
import { collectionPages } from "./collections";
import { dashboard } from "./dashboard";
import { layoutEdit, layoutIndex, redirectsIndex, sharedBlockEdit, sharedBlocksIndex } from "./design";
import { submissionsIndex, submissionView } from "./forms";
import { siteHealth } from "./health";
import { languageEdit, languageNew, languagesIndex } from "./languages";
import { cmsHostRules, linkPageSearch } from "./linkFields";
import { notFoundLog } from "./notFound";
import { notificationSettings } from "./notifications";
import { adminOptions, type CmsAdminConfig, type CmsAdminOptions } from "./options";
import { seoReport } from "./seoReport";
import { pageEdit } from "./pageEdit";
import { pagesIndex } from "./pages";
import { CMS_NAV_GROUPS, type CmsPanelLook, cmsPanel } from "./panel";
import { seoSettings, siteSettings } from "./settings";

export type CmsAdmin = {
	/**
	 * The whole createAdmin config in one spread: createAdmin({ ...cms.admin.options({ pages: mine }), rootView }).
	 * A key after the spread overrides; `pages` appends to the CMS's or, as a function, filters them.
	 */
	options: (options?: CmsAdminOptions) => CmsAdminConfig;
	/** The CMS's own pages, without the auth adapter's; options() is the usual way in. */
	pages: PageDef[];
	/** The CMS nav groups (content, design, settings), in CmsPanel order. */
	navigationGroups: NavGroupDef[];
	/**
	 * The CMS panel's look as PHP's CmsPanel sets it: brand, topbar-sidebar
	 * layout, compact full-width appearance, the nav groups and the chrome
	 * (bell, inbox, view site, user menu). createAdmin({ ...cms.admin.panel, … }).
	 */
	panel: CmsPanelLook;
	/** Request context for createAdmin({ context }): content locales from the admin-active languages. */
	context: (request: Request) => Promise<Partial<AdminContext>>;
	/** The host's `hostRules` plus the link, pages and collection fields' (`link_shape`, `page_ref`, `collection_value`): createAdmin({ hostRules: cms.admin.hostRules }); form submits use the same set. */
	hostRules: Record<string, HostRule>;
};

export function adminPages(cms: Cms): CmsAdmin {
	const own = cmsHostRules(cms);
	for (const name of Object.keys(cms.config.hostRules ?? {})) {
		if (Object.hasOwn(own, name)) {
			throw new TypeError(`hostRules.${name} is the CMS's own rule (the link, pages and collection fields'): give the host's rule another name.`);
		}
	}
	return {
		options: (options) => adminOptions(cms, options),
		pages: [
			// Any CMS editor (no tbtop-cms.* ability, as PHP); its submission parts check the inbox ability.
			dashboard(cms),
			gated(cms, "tbtop-cms.pages", pagesIndex(cms)),
			gated(cms, "tbtop-cms.pages", pageEdit(cms)),
			gated(cms, "tbtop-cms.submissions", submissionsIndex(cms)),
			gated(cms, "tbtop-cms.submissions", submissionView(cms)),
			gated(cms, "tbtop-cms.redirects", redirectsIndex(cms)),
			gated(cms, "tbtop-cms.redirects", notFoundLog(cms)),
			gated(cms, "tbtop-cms.redirects", seoReport(cms)),
			gated(cms, "tbtop-cms.design", layoutIndex(cms)),
			gated(cms, "tbtop-cms.design", layoutEdit(cms)),
			gated(cms, "tbtop-cms.design", sharedBlocksIndex(cms)),
			gated(cms, "tbtop-cms.design", sharedBlockEdit(cms)),
			gated(cms, "tbtop-cms.design", blocksShowcase(cms)),
			...collectionPages(cms),
			gated(cms, "tbtop-cms.languages", languagesIndex(cms)),
			gated(cms, "tbtop-cms.languages", languageNew(cms)),
			gated(cms, "tbtop-cms.languages", languageEdit(cms)),
			gated(cms, "tbtop-cms.settings", siteSettings(cms)),
			gated(cms, "tbtop-cms.settings", seoSettings(cms)),
			gated(cms, "tbtop-cms.settings", notificationSettings(cms)),
			gated(cms, "tbtop-cms.settings", siteHealth(cms)),
			gated(cms, "tbtop-cms.audit", auditLog(cms)),
			gated(cms, "tbtop-cms.audit", auditActivity(cms)),
			// Whoever edits a link field: the page editor, or the layout and shared blocks.
			{ ...linkPageSearch(cms), authorize: async () => (await allows(cms, "tbtop-cms.pages")) || (await allows(cms, "tbtop-cms.design")) },
		] as PageDef[],
		hostRules: { ...cms.config.hostRules, ...own },
		navigationGroups: [...CMS_NAV_GROUPS],
		panel: cmsPanel(cms),
		context: async (request) => {
			const languages = cms.site(request).languages;
			const locales = (await languages.adminActive()).map((row) => row.locale);
			const fallback = await languages.defaultLocale();
			return {
				contentLocales: locales.length > 0 ? locales : [fallback || "en"],
				defaultContentLocale: fallback || locales[0] || "en",
				...(cms.config.timezone === undefined ? {} : { timezone: cms.config.timezone }),
			};
		},
	};
}
