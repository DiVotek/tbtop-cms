import { type Admin, type AdminConfig, createAdmin, type Disk, memoryDisk, rootView } from "@tbtop/admin";
import type { Cms } from "../cms";
import { adminOptions, type CmsAdminOptions } from "./options";

/** createAdmin's config past what the CMS fills: the host's pages (array or filter), disks, root view and any panel option. */
export type CmsAdminOverrides = Omit<Partial<AdminConfig>, "pages" | "disks"> & CmsAdminOptions & { disks?: Record<string, Disk> };

/**
 * `createAdmin({ ...cms.admin.options({ pages }), disks, rootView })` with the
 * two keys a host always writes filled in: a root view without client assets
 * and a `public` disk. Without a disk uploads live in memory and are lost on
 * restart, so that default warns.
 */
export function createCmsAdmin(cms: Cms, options: CmsAdminOverrides = {}): Admin {
	const { pages, disks, rootView: view, ...rest } = options;
	return createAdmin({
		...adminOptions(cms, pages === undefined ? {} : { pages }),
		...rest,
		disks: disks ?? memoryDisks(),
		rootView: view ?? rootView(),
	});
}

function memoryDisks(): Record<string, Disk> {
	console.warn("tbtop/cms: no admin disk given; uploads are kept in memory and lost on restart.");
	return { public: memoryDisk("/storage") };
}
