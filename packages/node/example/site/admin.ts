import { definePage } from "@tbtop/admin";
import type { Cms } from "@tbtop/cms";
import type { SiteAdmin } from "@tbtop/cms/serve";
import type { site } from "./site";

/**
 * The host's own admin pages, next to the CMS's. A function of the CMS, so a
 * page can read it (on Workers there is no database before the request).
 */
export const admin: SiteAdmin<typeof site.def> = (cms: Cms) => ({
	pages: [
		definePage({
			path: "callbacks",
			title: "Callbacks",
			nav: { group: "Sales", label: "Callbacks", icon: "phone", order: 10 },
			view: async (s) => {
				const rows = await cms.config.db.selectFrom("tbtop_form_submissions").select(["id", "payload"]).where("kind", "=", "Callback").orderBy("id", "desc").limit(20).execute();
				return s.stack([
					s.displayText(`${rows.length} recent callback requests`).variant("heading"),
					...rows.map((row) => s.displayText(typeof row.payload === "string" ? row.payload : JSON.stringify(row.payload))),
				]);
			},
		}),
	],
});
