import type { AdminContext, PageDef } from "@tbtop/admin";
import type { Cms } from "../cms";
import { layoutEdit, layoutIndex, redirectsIndex, sharedBlockEdit, sharedBlocksIndex } from "./design";
import { languageEdit, languageNew, languagesIndex } from "./languages";
import { pageEdit, pagesIndex } from "./pages";
import { seoSettings, siteSettings } from "./settings";

export type CmsAdmin = {
	/** Spread into createAdmin({ pages: [...cms.admin.pages, ...mine] }). */
	pages: PageDef[];
	/** The CMS nav groups (content, design, settings), in CmsPanel order. */
	navigationGroups: { key: string; label: string; icon: string }[];
	/** Request context for createAdmin({ context }): content locales from the admin-active languages. */
	context: (request: Request) => Promise<Partial<AdminContext>>;
};

export function adminPages(cms: Cms): CmsAdmin {
	return {
		pages: [
			pagesIndex(cms),
			pageEdit(cms),
			redirectsIndex(cms),
			layoutIndex(cms),
			layoutEdit(cms),
			sharedBlocksIndex(cms),
			sharedBlockEdit(cms),
			languagesIndex(cms),
			languageNew(cms),
			languageEdit(cms),
			siteSettings(cms),
			seoSettings(cms),
		] as PageDef[],
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
