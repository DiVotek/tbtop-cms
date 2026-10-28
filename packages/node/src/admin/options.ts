import type { AdminConfig, PageDef } from "@tbtop/admin";
import { kyselyDriver } from "@tbtop/admin/kysely";
import type { Cms } from "../cms";
import { adminLocale, adminLocales, profileLocaleHooks } from "./locales";
import { adminPrefix } from "./support";

type Driver = NonNullable<AdminConfig["driver"]>;

/**
 * A drag reorder writes `sort_order` through the driver, past the entries
 * store (EntryBuilder::update): a write to `tbtop_entries` there also bumps
 * the content cache, so the site sees the new order.
 */
function entriesAware(driver: Driver, changed: () => Promise<void>): Driver {
	return {
		...driver,
		update: async (query, key, values) => {
			await driver.update(query, key, values);
			if (fromTable(query) === "tbtop_entries") {
				await changed();
			}
		},
	};
}

function fromTable(query: unknown): string | null {
	const node = (query as { toOperationNode?: () => { from?: { froms?: { table?: { identifier?: { name?: string } } }[] } } }).toOperationNode?.();
	return node?.from?.froms?.[0]?.table?.identifier?.name ?? null;
}

/** The host's pages after the CMS's, or a function that gets the CMS's (auth pages included) and returns the panel's. */
export type CmsAdminPages = PageDef[] | ((cmsPages: PageDef[]) => PageDef[]);

export type CmsAdminOptions = {
	pages?: CmsAdminPages;
};

/** createAdmin's config with what the CMS always fills; the rest (rootView, disks) the host adds. */
export type CmsAdminConfig = Partial<AdminConfig> & Pick<AdminConfig, "prefix" | "pages">;

/**
 * Everything createAdmin needs from the CMS, read from its config: the look,
 * the prefix, the pages, the rules, the context, the UI locales saved per
 * admin profile, the auth adapter's session
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
		driver: entriesAware(kyselyDriver(config.db), () => cms.site().entries.changed()),
		hostRules: admin.hostRules,
		context: admin.context,
		locales: adminLocales(config),
		locale: adminLocale(config),
		...profileLocaleHooks(config),
		...(config.auth === undefined ? {} : { session: config.auth.session, loginPath: config.auth.loginPath(cms) }),
		...(config.auth?.userMenuItems === undefined ? {} : { userMenuItems: config.auth.userMenuItems(cms) }),
		...(bell === undefined ? {} : { notifications: { store: bell.store } }),
	};
}
