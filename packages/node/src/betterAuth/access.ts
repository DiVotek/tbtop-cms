import { type AccessControl, createAccessControl } from "better-auth/plugins/access";
import { adminAc, defaultStatements } from "better-auth/plugins/admin/access";
import type { CmsAbility } from "../admin/abilities";

/** The CMS sections a role may open, next to the admin plugin's own `user` and `session` statements. */
export const CMS_SECTIONS = ["pages", "submissions", "redirects", "settings", "design", "languages"] as const;

export const cmsStatements = { ...defaultStatements, cms: CMS_SECTIONS } as const;

/** The access control to pass to `admin({ ac: cmsAccess, roles })`; `cmsAccess.newRole(...)` makes a host's own roles. */
export const cmsAccess: AccessControl<typeof cmsStatements> = createAccessControl(cmsStatements);

/**
 * Default roles: `admin` holds every section and manages users, `editor`
 * edits content and design and reads the inbox, `user` (the admin plugin's
 * default role) does not get into the panel.
 */
export const cmsRoles = {
	admin: cmsAccess.newRole({ ...adminAc.statements, cms: [...CMS_SECTIONS] }),
	editor: cmsAccess.newRole({ cms: ["pages", "submissions", "redirects", "design"] }),
	user: cmsAccess.newRole({ cms: [] }),
};

/** What the adapter asks of a role: the admin plugin's `role.authorize(request)`. */
export type CmsRole = { authorize(request: Record<string, string[]>): { success: boolean } };

/**
 * The permission an ability maps to. Sections are `cms:<section>`; the
 * Users page needs the admin plugin's `user:list`, so a role manages users
 * in the CMS exactly when Better Auth lets it.
 */
export function permissionFor(ability: CmsAbility): Record<string, string[]> {
	return ability === "tbtop-cms.users" ? { user: ["list"] } : { cms: [ability.slice("tbtop-cms.".length)] };
}

/** Whether any of the user's roles (a comma-separated list, as the admin plugin stores it) grants `ability`. */
export function roleAllows(roles: Record<string, CmsRole>, role: string, ability: CmsAbility): boolean {
	const request = permissionFor(ability);
	return role.split(",").some((name) => Object.hasOwn(roles, name.trim()) && roles[name.trim()]?.authorize(request).success === true);
}
