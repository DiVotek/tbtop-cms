import type { ChromeDef, NavGroupDef, S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { allows } from "./abilities";
import { adminPrefix } from "./support";

/** The product brand (CmsPanel ->brand()): the topbar and the login screen. */
export const CMS_BRAND = "TableTop CMS";

/** CmsPanel::navigationGroups: content open, design and settings collapsed. */
export const CMS_NAV_GROUPS = [
	{ key: "content", label: "Content", icon: "file-text", collapsible: true },
	{ key: "design", label: "Design", icon: "star", collapsed: true },
	{ key: "settings", label: "Settings", icon: "lock", collapsed: true },
] as const satisfies NavGroupDef[];

/** The CMS panel's look (CmsPanel::configure): spread into createAdmin({ ...cms.admin.panel }). */
export type CmsPanelLook = {
	brand: string;
	navigation: "topbar-sidebar";
	navigationGroups: NavGroupDef[];
	appearance: { density: "compact"; maxContentWidth: "full" };
	chrome: ChromeDef;
};

export function cmsPanel(cms: Cms): CmsPanelLook {
	return {
		brand: CMS_BRAND,
		navigation: "topbar-sidebar",
		navigationGroups: [...CMS_NAV_GROUPS],
		appearance: { density: "compact", maxContentWidth: "full" },
		chrome: cmsChrome(cms),
	};
}

/**
 * CmsChrome: the sidebar is the nav alone (the brand sits in the topbar);
 * the header carries the bell, the inbox with its unread count, a link to
 * the site under its name, and the user menu without the locale switcher.
 */
export function cmsChrome(cms: Cms): ChromeDef {
	return {
		sidebarItems: (s) => [s.navMenu()],
		// The whole area, so the inbox can be left out for an admin without its ability.
		header: async (s) => {
			const box = await inbox(s, cms);
			return s.flex([s.notifications(), ...(box === null ? [] : [box]), await viewSite(s, cms), s.userMenu({ locales: false })], { align: "center" });
		},
	};
}

async function viewSite(s: S, cms: Cms) {
	const name = String((await cms.site().settings.get("site.name")) ?? "").trim();
	return s
		.action("view-site")
		.label(name !== "" ? name : "View site")
		.icon("external-link")
		.tooltip("Open the site in a new tab")
		.size("sm")
		.url("/")
		.openUrlInNewTab();
}

/** The inbox link with its unread count; absent for an admin without the inbox ability. */
async function inbox(s: S, cms: Cms) {
	if (!(await allows(cms, "tbtop-cms.submissions"))) {
		return null;
	}
	const row = await cms.config.db
		.selectFrom("tbtop_form_submissions")
		.select((eb) => eb.fn.countAll().as("n"))
		.where("lead_status", "=", "new")
		.executeTakeFirst();
	const unread = Number(row?.n ?? 0);
	const action = s.action("inbox").label("Inbox").icon("inbox").tooltip("Form submissions").size("sm").url(`${adminPrefix(cms)}/form-submissions`);
	return unread > 0 ? action.badge(unread, "danger") : action;
}
