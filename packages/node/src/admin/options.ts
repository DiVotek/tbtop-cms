import type { AdminConfig, PageDef } from "@tbtop/admin";
import { kyselyDriver } from "@tbtop/admin/kysely";
import type { Cms } from "../cms";
import { adminPrefix } from "./support";

/** The host's pages after the CMS's, or a function that gets the CMS's (auth pages included) and returns the panel's. */
export type CmsAdminPages = PageDef[] | ((cmsPages: PageDef[]) => PageDef[]);

export type CmsAdminOptions = {
	pages?: CmsAdminPages;
};

/** createAdmin's config with what the CMS always fills; the rest (rootView, disks) the host adds. */
export type CmsAdminConfig = Partial<AdminConfig> & Pick<AdminConfig, "prefix" | "pages">;

/**
 * Everything createAdmin needs from the CMS, read from its config: the look,
 * the prefix, the pages, the rules, the context, the auth adapter's session
 * and login path, the bell's store and a Kysely driver over `config.db`.
 */
export function adminOptions(cms: Cms, options: CmsAdminOptions = {}): CmsAdminConfig {
	const { config, admin } = cms;
	const cmsPages = [...admin.pages, ...(config.auth?.pages(cms) ?? [])];
	const pages = typeof options.pages === "function" ? options.pages(cmsPages) : [...cmsPages, ...(options.pages ?? [])];
	const bell = config.notifications?.bell;
	return {
		...admin.panel,
		prefix: adminPrefix(cms),
		pages,
		driver: kyselyDriver(config.db),
		hostRules: admin.hostRules,
		context: admin.context,
		...(config.auth === undefined ? {} : { session: config.auth.session, loginPath: config.auth.loginPath(cms) }),
		...(bell === undefined ? {} : { notifications: { store: bell.store } }),
	};
}
