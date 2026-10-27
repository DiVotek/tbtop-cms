import { type BetterAuthOptions, betterAuth } from "better-auth";
import { getMigrations } from "better-auth/db/migration";
import { admin as adminPlugin } from "better-auth/plugins";
import type { Kysely } from "kysely";
import { dialectOfDb } from "../support/db";
import { cmsAccess, cmsRoles } from "./access";
import { type BetterAuthLike, type CmsAuth, type CmsAuthOptions, cmsAuth } from "./index";

/** A role from `cmsAccess.newRole(...)`: the admin plugin and the adapter both take it. */
export type CmsAccessRole = ReturnType<typeof cmsAccess.newRole>;

/** Better Auth as the package builds it from the host's `db`, with what the host wires around it. */
export type DefaultAuth = {
	/** The Better Auth instance, for the host's own client sign-in, OAuth or plugins. */
	instance: BetterAuthLike & { handler(request: Request): Promise<Response> };
	/** createCms({ auth }) */
	adapter: CmsAuth;
	/** Better Auth's tables next to the CMS's. Idempotent. */
	migrate(): Promise<void>;
	/**
	 * Creates the user with this role, or leaves an existing email as it is
	 * (its role unchanged); the user's id. It skips Better Auth's permission
	 * check (no request), which is what a seed, a test or a CLI needs: never
	 * pass it request input. The `admin` role is refused: the first admin
	 * comes from `TBTOP_ADMIN_EMAIL` / `TBTOP_ADMIN_PASSWORD`, the rest from the Users page.
	 */
	ensureUser(email: string, role: string, options: { password: string; name?: string }): Promise<string>;
	/** Better Auth's HTTP API under `basePath`; null for every other URL. */
	handle(request: Request): Promise<Response | null>;
};

export type DefaultAuthOptions = {
	/** Signs sessions; default `BETTER_AUTH_SECRET`. Required: there is no default secret. */
	secret?: string;
	/** Default `BETTER_AUTH_URL`; unset, Better Auth infers it from the request. */
	baseURL?: string;
	/** Better Auth's own database when the Kysely instance is not enough (D1: the binding itself). */
	database?: BetterAuthOptions["database"];
	/** The roles, given to both the admin plugin and the adapter; default `cmsRoles`. */
	roles?: Record<string, CmsAccessRole>;
	/** Anything else of Better Auth (social providers, plugins, sign-up), merged over the defaults; plugins are appended. */
	betterAuth?: Partial<BetterAuthOptions>;
	adapter?: Omit<CmsAuthOptions, "roles">;
	/** Default `/api/auth`. */
	basePath?: string;
};

const firstAdmins = new WeakMap<DefaultAuth, (email: string, password: string) => Promise<string>>();

/** The first admin from the host's secrets, on boot; not exported from the package. */
export function ensureFirstAdmin(auth: DefaultAuth, email: string, password: string): Promise<string> {
	const ensure = firstAdmins.get(auth);
	if (ensure === undefined) {
		throw new TypeError("tbtop/cms: not a defaultAuth() result.");
	}
	return ensure(email, password);
}

const env = (name: string): string | undefined => (typeof process === "undefined" ? undefined : process.env[name]);

/**
 * Better Auth from the host's `db`: the dialect read from the Kysely adapter,
 * the `tbtop_*` tables, the admin plugin with the CMS roles, email and
 * password sign-in with public sign-up off (admins add users on the Users
 * page). `createCms({ auth: auth.adapter })`.
 */
export function defaultAuth(db: Kysely<unknown>, options: DefaultAuthOptions = {}): DefaultAuth {
	const roles: Record<string, CmsAccessRole> = options.roles ?? cmsRoles;
	const basePath = options.basePath ?? "/api/auth";
	const secret = options.secret ?? env("BETTER_AUTH_SECRET");
	const baseURL = options.baseURL ?? env("BETTER_AUTH_URL");
	if (secret === undefined || secret === "") {
		throw new Error("tbtop/cms: set BETTER_AUTH_SECRET (or pass `secret`): sessions are never signed with a default secret.");
	}
	const config = {
		...defaultAuthSchema({ ...options, roles }),
		database: options.database ?? { db, type: databaseType(db) },
		basePath,
		secret,
		...(baseURL === undefined ? {} : { baseURL }),
	} satisfies BetterAuthOptions;
	const instance = betterAuth(config) as unknown as DefaultAuth["instance"];
	const users = db as Kysely<{ tbtop_users: { id: string; email: string; role: string | null } }>;
	const ensureUser = async (email: string, role: string, { password, name }: { password: string; name?: string }): Promise<string> => {
		const found = await users.selectFrom("tbtop_users").select("id").where("email", "=", email).executeTakeFirst();
		if (found !== undefined) {
			return found.id;
		}
		// No headers: Better Auth's createUser checks the caller's role only when a request is given.
		const createUser = instance.api.createUser as unknown as (input: { body: Record<string, unknown> }) => Promise<{ user: { id: string } }>;
		return (await createUser({ body: { email, password, name: name ?? email.split("@")[0] ?? email, role } })).user.id;
	};
	const auth: DefaultAuth = {
		instance,
		adapter: cmsAuth(instance, { ...options.adapter, roles }),
		migrate: async () => (await getMigrations(config)).runMigrations(),
		ensureUser: (email, role, opts) => {
			if (role === "admin") {
				return Promise.reject(new Error("tbtop/cms: ensureUser does not create admins; set TBTOP_ADMIN_EMAIL / TBTOP_ADMIN_PASSWORD."));
			}
			if (!Object.hasOwn(roles, role)) {
				return Promise.reject(new Error(`tbtop/cms: unknown role "${role}"; one of ${Object.keys(roles).join(", ")}.`));
			}
			return ensureUser(email, role, opts);
		},
		handle: async (request) => (new URL(request.url).pathname.startsWith(`${basePath}/`) ? instance.handler(request) : null),
	};
	// Only while the site has no admin: a deleted or demoted first admin stays so, whatever the secrets say.
	firstAdmins.set(auth, async (email, password) => {
		const admin = await users
			.selectFrom("tbtop_users")
			.select("id")
			.where((eb) => eb.or([eb("role", "=", "admin"), eb("role", "like", "admin,%"), eb("role", "like", "%,admin"), eb("role", "like", "%,admin,%")]))
			.executeTakeFirst();
		if (admin !== undefined) {
			return admin.id;
		}
		if ((await users.selectFrom("tbtop_users").select("id").where("email", "=", email).executeTakeFirst()) !== undefined) {
			throw new Error(`tbtop/cms: TBTOP_ADMIN_EMAIL ${email} belongs to an existing user who is not an admin; use another email or make that user an admin.`);
		}
		return ensureUser(email, "admin", { password });
	});
	return auth;
}

/**
 * Better Auth's options without the database and secrets: the `tbtop_*`
 * models, the admin plugin with the roles, email and password with public
 * sign-up off, then the host's extras. They decide Better Auth's tables, so
 * the D1 SQL files (`tbtop-cms d1:migrations`) are generated from them.
 */
export function defaultAuthSchema(options: Pick<DefaultAuthOptions, "roles" | "betterAuth"> = {}) {
	const extra = options.betterAuth ?? {};
	return {
		...extra,
		emailAndPassword: { enabled: true, disableSignUp: true, ...extra.emailAndPassword },
		user: { ...extra.user, modelName: "tbtop_users" },
		session: { ...extra.session, modelName: "tbtop_sessions" },
		account: { ...extra.account, modelName: "tbtop_accounts" },
		verification: { ...extra.verification, modelName: "tbtop_verifications" },
		plugins: [adminPlugin({ ac: cmsAccess, roles: options.roles ?? cmsRoles }), ...(extra.plugins ?? [])],
		// The tables exist before the first request (migrate() or the D1 files); Better Auth's own check at construction would race migrate() on a fresh database.
		advanced: { ...extra.advanced, database: { ...extra.advanced?.database, validateSchema: false } },
	} satisfies BetterAuthOptions;
}

function databaseType(db: Kysely<unknown>): "sqlite" | "postgres" | "mysql" {
	const dialect = dialectOfDb(db);
	if (dialect === null) {
		throw new TypeError("tbtop/cms: unknown Kysely dialect; pass `database` to defaultAuth.");
	}
	return dialect;
}
