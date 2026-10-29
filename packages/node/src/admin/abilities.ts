import { currentContext, type PageDef } from "@tbtop/admin";
import type { Cms } from "../cms";

/**
 * The section abilities CMS admin pages ask `authorize(user, ability)` for,
 * by their PHP names (CmsAbilities::ALL, minus sections Node has no pages
 * for yet). Design covers the layout and shared blocks; settings covers
 * site, SEO and notification settings and site health; submissions is the inbox; audit is the audit log; users is
 * the Users page that an auth adapter (`@tbtop/cms/better-auth`) adds.
 */
export const CMS_ABILITIES = [
	"tbtop-cms.pages",
	"tbtop-cms.submissions",
	"tbtop-cms.redirects",
	"tbtop-cms.settings",
	"tbtop-cms.design",
	"tbtop-cms.languages",
	"tbtop-cms.audit",
	"tbtop-cms.users",
] as const;

export type CmsAbility = (typeof CMS_ABILITIES)[number];

/** Whether the signed-in admin holds `ability`; `authorize` wins over `auth.authorize`, and without both every section is open. */
export async function allows(cms: Cms, ability: CmsAbility): Promise<boolean> {
	const check = cms.config.authorize ?? cms.config.auth?.authorize;
	return check === undefined || (await check(currentContext().user, ability)) === true;
}

/**
 * The page behind `ability`: @tbtop/admin hides it from the nav and answers
 * 403 to a visit and to each of its forms, actions, tables and uploads,
 * since every endpoint resolves the page first.
 */
export function gated<P extends PageDef>(cms: Cms, ability: CmsAbility, page: P): P {
	const own = page.authorize;
	return {
		...page,
		authorize: async (ctx) => (await allows(cms, ability)) && (own === undefined || (await own(ctx))),
	};
}
