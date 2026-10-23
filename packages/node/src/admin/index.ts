import type { AdminContext, HostRule, PageDef } from "@tbtop/admin";
import type { Cms } from "../cms";
import { gated } from "./abilities";
import { dashboard } from "./dashboard";
import { layoutEdit, layoutIndex, redirectsIndex, sharedBlockEdit, sharedBlocksIndex } from "./design";
import { submissionsIndex, submissionView } from "./forms";
import { languageEdit, languageNew, languagesIndex } from "./languages";
import { cmsHostRules, linkPageSearch } from "./linkFields";
import { notFoundLog } from "./notFound";
import { notificationSettings } from "./notifications";
import { seoReport } from "./seoReport";
import { pageEdit, pagesIndex } from "./pages";
import { seoSettings, siteSettings } from "./settings";

export type CmsAdmin = {
	/** Spread into createAdmin({ pages: [...cms.admin.pages, ...mine] }). */
	pages: PageDef[];
	/** The CMS nav groups (content, design, settings), in CmsPanel order. */
	navigationGroups: { key: string; label: string; icon: string }[];
	/** Request context for createAdmin({ context }): content locales from the admin-active languages. */
	context: (request: Request) => Promise<Partial<AdminContext>>;
	/** The host's `hostRules` plus the link and pages fields' (`link_shape`, `page_ref`): createAdmin({ hostRules: cms.admin.hostRules }); form submits use the same set. */
	hostRules: Record<string, HostRule>;
};

export function adminPages(cms: Cms): CmsAdmin {
	return {
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
			gated(cms, "tbtop-cms.languages", languagesIndex(cms)),
			gated(cms, "tbtop-cms.languages", languageNew(cms)),
			gated(cms, "tbtop-cms.languages", languageEdit(cms)),
			gated(cms, "tbtop-cms.settings", siteSettings(cms)),
			gated(cms, "tbtop-cms.settings", seoSettings(cms)),
			gated(cms, "tbtop-cms.settings", notificationSettings(cms)),
			// Any CMS editor, as PHP guards the route with EnsureCmsAdmin only: layout and shared blocks use link fields too.
			linkPageSearch(cms),
		] as PageDef[],
		hostRules: { ...cms.config.hostRules, ...cmsHostRules(cms) },
		navigationGroups: [
			{ key: "content", label: "Content", icon: "files" },
			{ key: "design", label: "Design", icon: "palette" },
			{ key: "settings", label: "Settings", icon: "settings" },
		],
		context: async (request) => {
			const languages = cms.site(request).languages;
			const locales = (await languages.adminActive()).map((row) => row.locale);
			const fallback = await languages.defaultLocale();
			return {
				contentLocales: locales.length > 0 ? locales : [fallback || "en"],
				defaultContentLocale: fallback || locales[0] || "en",
			};
		},
	};
}
