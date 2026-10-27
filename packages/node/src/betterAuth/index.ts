import { cookieSessions, type NavLink, type PageDef, SESSION_USER, type SessionResolver } from "@tbtop/admin";
import { CMS_ABILITIES, type CmsAbility } from "../admin/abilities";
import { adminPrefix } from "../admin/support";
import type { Cms } from "../cms";
import { SubmitThrottle, type ThrottleLimit } from "../forms/throttle";
import { type CmsRole, cmsRoles, roleAllows } from "./access";
import { loginPage } from "./login";
import { userEdit, userNew, usersIndex } from "./pages";
import { profilePage } from "./profile";
import { AuthSession, type CmsAuthUser } from "./session";

export { CMS_SECTIONS, cmsAccess, cmsRoles, cmsStatements, type CmsRole, permissionFor } from "./access";
export type { CmsAuthUser } from "./session";
export { type CmsAccessRole, type DefaultAuth, type DefaultAuthOptions, defaultAuth, defaultAuthSchema } from "./defaultAuth";

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
		signInEmail(input: Call<{ email: string; password: string; rememberMe?: boolean }> & { asResponse: true }): Promise<Response>;
		signOut(input: { headers: Headers; asResponse: true }): Promise<Response>;
		createUser(input: Call<{ email: string; password: string; name: string; role: RoleName }>): Promise<unknown>;
		setRole(input: Call<{ userId: string; role: RoleName }>): Promise<unknown>;
		adminUpdateUser(input: Call<{ userId: string; data: Record<string, unknown> }>): Promise<unknown>;
		setUserPassword(input: Call<{ userId: string; newPassword: string }>): Promise<unknown>;
		revokeUserSessions(input: Call<{ userId: string }>): Promise<unknown>;
		revokeOtherSessions(input: { headers: Headers }): Promise<unknown>;
		updateUser(input: Call<{ name: string }> & { asResponse: true }): Promise<Response>;
		changePassword(input: Call<{ currentPassword: string; newPassword: string; revokeOtherSessions?: boolean }> & { asResponse: true }): Promise<Response>;
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
	/**
	 * Sign-in attempts per client and email on the login page (Laravel's
	 * login throttle); default 5 a minute, false turns it off. Better Auth's
	 * own rate limit covers its HTTP routes only, and every attempt costs a
	 * password hash. Counted in memory, like `forms.throttle`; the client
	 * comes from `forms.clientIp` or the proxy headers.
	 */
	signInThrottle?: ThrottleLimit | false;
	/** The Profile page (own name and password) and its user-menu link; false leaves both out, e.g. behind SSO. Default true. */
	profile?: boolean;
};

/** createCms({ auth: cmsAuth(betterAuth) }): satisfies CmsAdminAuth, plus signIn for custom login pages. */
export type CmsAuth = {
	/** The user's roles against the section's permission; createCms({ auth }) reads it. */
	authorize: (user: unknown, ability: CmsAbility) => boolean;
	/** The panel's user is Better Auth's; logout signs out there. cms.admin.options() passes it. */
	session: SessionResolver;
	/** The sign-in page under the admin prefix; cms.admin.options() passes it. */
	loginPath: (cms: Cms) => string;
	/** The sign-in, Users and Profile pages; cms.admin.options() adds them after the CMS's. */
	pages: (cms: Cms) => PageDef[];
	/** The Profile link at the top of the user menu; cms.admin.options() passes it. */
	userMenuItems: (cms: Cms) => NavLink[];
	/** Signs in with email and password (remember: Better Auth's rememberMe, default false): the cookies go on the panel's response; false for wrong credentials, a ban or no CMS role. */
	signIn: (request: Request, email: string, password: string, remember?: boolean) => Promise<boolean>;
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

	const signIn = async (request: Request, email: string, password: string, remember = false): Promise<boolean> => {
		const store = stores.get(request);
		if (store === undefined) {
			throw new TypeError("signIn() runs inside a panel request: pass createCms({ auth }) and createAdmin(cms.admin.options()).");
		}
		const response = await auth.api.signInEmail({ body: { email, password, rememberMe: remember }, headers: request.headers, asResponse: true });
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
	const throttle = options.signInThrottle === false ? null : new SubmitThrottle(options.signInThrottle ?? { max: 5, perSeconds: 60 });
	const profile = options.profile ?? true;
	const user = (request: Request) => (stores.get(request)?.get(SESSION_USER) as CmsAuthUser | null | undefined) ?? null;
	const carry = (request: Request, response: Response) => stores.get(request)?.carry(response);
	return {
		authorize: (user, ability) => user !== null && typeof user === "object" && allows(user as CmsAuthUser, ability),
		session,
		signIn,
		loginPath: (cms) => `${adminPrefix(cms)}/login`,
		pages: (cms) => {
			const ctx = { cms, auth, roles, usersTable, signIn, throttle, user, carry };
			return [loginPage(ctx), usersIndex(ctx), userNew(ctx), userEdit(ctx), ...(profile ? [profilePage(ctx)] : [])] as PageDef[];
		},
		userMenuItems: (cms) => (profile ? [{ label: "Profile", url: `${adminPrefix(cms)}/profile`, icon: "user" }] : []),
	};
}

export type AdapterCtx = {
	cms: Cms;
	auth: BetterAuthLike;
	roles: Record<string, CmsRole>;
	usersTable: string;
	signIn: CmsAuth["signIn"];
	throttle: SubmitThrottle | null;
	/** The panel's user on this request (null for a guest). */
	user: (request: Request) => CmsAuthUser | null;
	/** Puts a Better Auth answer's Set-Cookie on the panel's response. */
	carry: (request: Request, response: Response) => void;
};
