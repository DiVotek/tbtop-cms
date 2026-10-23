import { cookieSessions, type PageDef, type SessionResolver } from "@tbtop/admin";
import { CMS_ABILITIES, type CmsAbility } from "../admin/abilities";
import type { Cms } from "../cms";
import { type CmsRole, cmsRoles, roleAllows } from "./access";
import { loginPage, userEdit, userNew, usersIndex } from "./pages";
import { AuthSession, type CmsAuthUser } from "./session";

export { CMS_SECTIONS, cmsAccess, cmsRoles, cmsStatements, type CmsRole, permissionFor } from "./access";
export type { CmsAuthUser } from "./session";

type Call<B> = { body: B; headers: Headers };

/**
 * A role name. Better Auth types it as the union of the host's roles; `never`
 * here lets any instance fit, and the adapter passes the names it validated.
 */
type RoleName = never;

/**
 * The part of a Better Auth instance the adapter calls: `auth.api` with
 * email and password sign-in and the admin plugin.
 */
export type BetterAuthLike = {
	api: {
		getSession(input: { headers: Headers }): Promise<{ user: object } | null>;
		signInEmail(input: Call<{ email: string; password: string }> & { asResponse: true }): Promise<Response>;
		signOut(input: { headers: Headers; asResponse: true }): Promise<Response>;
		createUser(input: Call<{ email: string; password: string; name: string; role: RoleName }>): Promise<unknown>;
		setRole(input: Call<{ userId: string; role: RoleName }>): Promise<unknown>;
		adminUpdateUser(input: Call<{ userId: string; data: Record<string, unknown> }>): Promise<unknown>;
		setUserPassword(input: Call<{ userId: string; newPassword: string }>): Promise<unknown>;
		banUser(input: Call<{ userId: string }>): Promise<unknown>;
		unbanUser(input: Call<{ userId: string }>): Promise<unknown>;
		removeUser(input: Call<{ userId: string }>): Promise<unknown>;
	};
	options: { user?: { modelName?: string }; secret?: string };
	$context: Promise<{ secret: string }>;
};

export type CmsAuthOptions = {
	/** The roles given to the admin plugin; default `cmsRoles`. */
	roles?: Record<string, CmsRole>;
	/** Where flashed errors and effects live; default an encrypted cookie keyed by Better Auth's secret. */
	flash?: SessionResolver;
	/** The users table: Better Auth's `user.modelName` (default `user`). */
	usersTable?: string;
};

export type CmsAuth = {
	/** createCms({ authorize }): the user's roles against the section's permission. */
	authorize: (user: unknown, ability: CmsAbility) => boolean;
	/** createAdmin({ session }): the panel's user is Better Auth's; logout signs out there. */
	session: SessionResolver;
	/** createAdmin({ loginPath }). */
	loginPath: (cms: Cms) => string;
	/** The sign-in page and the Users pages: createAdmin({ pages: [...cms.admin.pages, ...auth.pages(cms)] }). */
	pages: (cms: Cms) => PageDef[];
	/** Signs in with email and password: the cookies go on the panel's response; false for wrong credentials, a ban or no CMS role. */
	signIn: (request: Request, email: string, password: string) => Promise<boolean>;
};

const asUser = (raw: object): CmsAuthUser => {
	const user = raw as Record<string, unknown>;
	return {
		id: String(user.id),
		name: String(user.name ?? ""),
		email: String(user.email ?? ""),
		role: typeof user.role === "string" ? user.role : "",
		image: typeof user.image === "string" ? user.image : null,
	};
};

/**
 * `@tbtop/cms/better-auth`: the admin panel's sign-in, session and section
 * access over a Better Auth instance (the admin plugin, its access control
 * with `cmsStatements`, email and password). A user with no CMS section and
 * no `user:list` permission is a guest to the panel.
 */
export function cmsAuth(auth: BetterAuthLike, options: CmsAuthOptions = {}): CmsAuth {
	const roles = options.roles ?? cmsRoles;
	const allows = (user: CmsAuthUser, ability: CmsAbility) => roleAllows(roles, user.role, ability);
	const staff = (user: CmsAuthUser) => CMS_ABILITIES.some((ability) => allows(user, ability));
	const flash =
		options.flash ??
		(async (request: Request) => cookieSessions({ secret: (await auth.$context).secret, cookie: "tbtop_cms_flash" })(request));
	const stores = new WeakMap<Request, AuthSession>();

	const session: SessionResolver = async (request) => {
		const found = await auth.api.getSession({ headers: request.headers });
		const user = found === null ? null : asUser(found.user);
		const store = new AuthSession(await flash(request), user !== null && staff(user) ? user : null, () =>
			auth.api.signOut({ headers: request.headers, asResponse: true }),
		);
		stores.set(request, store);
		return store;
	};

	const signIn = async (request: Request, email: string, password: string): Promise<boolean> => {
		const store = stores.get(request);
		if (store === undefined) {
			throw new TypeError("signIn() runs inside a panel request: pass createAdmin({ session: auth.session }).");
		}
		const response = await auth.api.signInEmail({ body: { email, password }, headers: request.headers, asResponse: true });
		if (!response.ok) {
			return false;
		}
		const body = (await response.json()) as { user?: object };
		if (body.user !== undefined && staff(asUser(body.user))) {
			store.carry(response);
			return true;
		}
		// Right password, no CMS role: end the session Better Auth just opened.
		const cookie = response.headers
			.getSetCookie()
			.map((line) => line.split(";")[0])
			.join("; ");
		await auth.api.signOut({ headers: new Headers({ cookie }), asResponse: true });
		return false;
	};

	const usersTable = options.usersTable ?? auth.options.user?.modelName ?? "user";
	return {
		authorize: (user, ability) => user !== null && typeof user === "object" && allows(user as CmsAuthUser, ability),
		session,
		signIn,
		loginPath: (cms) => `/${(cms.config.adminPrefix ?? "admin").replace(/^\/+|\/+$/g, "")}/login`,
		pages: (cms) => {
			const ctx = { cms, auth, roles, usersTable, signIn };
			return [loginPage(ctx), usersIndex(ctx), userNew(ctx), userEdit(ctx)] as PageDef[];
		},
	};
}

export type AdapterCtx = { cms: Cms; auth: BetterAuthLike; roles: Record<string, CmsRole>; usersTable: string; signIn: CmsAuth["signIn"] };
