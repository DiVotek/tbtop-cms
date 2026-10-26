import { betterAuth, type BetterAuthOptions } from "better-auth";
import { getMigrations } from "better-auth/db/migration";
import { admin as adminPlugin } from "better-auth/plugins";
import type { Kysely } from "kysely";
import { type BetterAuthLike, type CmsAuth, type CmsAuthOptions, cmsAccess, cmsAuth, cmsRoles } from "../../../src/betterAuth";

/** A role from `cmsAccess.newRole(...)`: the admin plugin and the adapter both take it. */
export type CmsAccessRole = ReturnType<typeof cmsAccess.newRole>;
import { dialectOf } from "./dialect";

/** What the package builds from the host's `db` alone: Better Auth, its adapter, migrations and the seed helper. */
export type DefaultAuth = {
	/** The Better Auth instance, for the host's own client sign-in or OAuth. */
	instance: BetterAuthLike & { handler(request: Request): Promise<Response> };
	/** createCms({ auth }) */
	adapter: CmsAuth;
	/** Better Auth's tables next to the CMS's. */
	migrate(): Promise<void>;
	/** Creates the user, or leaves an existing email alone (its role is not changed). The user's id. */
	ensureUser(email: string, role: string, options: { password: string; name?: string }): Promise<string>;
	/** Better Auth's HTTP API under `basePath` (default `/api/auth`); null for every other URL. */
	handle(request: Request): Promise<Response | null>;
};

export type DefaultAuthOptions = {
	/** Default `BETTER_AUTH_SECRET`; on Workers the cloudflare entry passes it from `env`. */
	secret?: string;
	/** Default `BETTER_AUTH_URL`, else the CMS `url`. */
	baseURL?: string;
	/** Raw database for Better Auth when the Kysely instance is not enough (D1: the binding itself). */
	database?: BetterAuthOptions["database"];
	/** Host roles, given to both the admin plugin and the adapter. */
	roles?: Record<string, CmsAccessRole>;
	/** Anything else of Better Auth (social providers, plugins), merged over the defaults; plugins are appended. */
	betterAuth?: Partial<BetterAuthOptions>;
	adapter?: Omit<CmsAuthOptions, "roles">;
	basePath?: string;
};

const env = (name: string): string | undefined => (typeof process === "undefined" ? undefined : process.env[name]);

/**
 * Q15: the host passes `db`, the package makes Better Auth itself: the
 * dialect from the Kysely adapter, the `tbtop_*` models, the admin plugin
 * with the CMS roles, email and password.
 */
export function defaultAuth(db: Kysely<unknown>, options: DefaultAuthOptions = {}): DefaultAuth {
	const roles: Record<string, CmsAccessRole> = options.roles ?? cmsRoles;
	const basePath = options.basePath ?? "/api/auth";
	const extra = options.betterAuth ?? {};
	const secret = options.secret ?? env("BETTER_AUTH_SECRET");
	const baseURL = options.baseURL ?? env("BETTER_AUTH_URL");
	const config = {
		...extra,
		database: options.database ?? { db, type: dialectOf(db) },
		basePath,
		...(secret === undefined ? {} : { secret }),
		...(baseURL === undefined ? {} : { baseURL }),
		// Admin users come from ensureUser / the admin plugin; public sign-up is opt-in.
		emailAndPassword: { enabled: true, disableSignUp: true, ...extra.emailAndPassword },
		user: { ...extra.user, modelName: "tbtop_users" },
		session: { ...extra.session, modelName: "tbtop_sessions" },
		account: { ...extra.account, modelName: "tbtop_accounts" },
		verification: { ...extra.verification, modelName: "tbtop_verifications" },
		plugins: [adminPlugin({ ac: cmsAccess, roles }), ...(extra.plugins ?? [])],
		// The package migrates before it serves; Better Auth's own check would race that on a fresh database.
		advanced: { ...extra.advanced, database: { ...extra.advanced?.database, validateSchema: false } },
	} satisfies BetterAuthOptions;
	const instance = betterAuth(config) as unknown as DefaultAuth["instance"];
	const users = db as Kysely<{ tbtop_users: { id: string; email: string } }>;
	return {
		instance,
		adapter: cmsAuth(instance, { ...options.adapter, roles }),
		migrate: async () => (await getMigrations(config)).runMigrations(),
		ensureUser: async (email, role, { password, name }) => {
			const found = await users.selectFrom("tbtop_users").select("id").where("email", "=", email).executeTakeFirst();
			if (found !== undefined) {
				return found.id;
			}
			// Server-side, no headers: Better Auth's createUser skips the admin check without a
			// request (the proofs log in as a user made this way). So this is boot/CLI only:
			// never pass request input to it, it can mint an admin.
			const createUser = instance.api.createUser as unknown as (input: { body: Record<string, unknown> }) => Promise<{ user: { id: string } }>;
			const made = await createUser({ body: { email, password, name: name ?? email.split("@")[0] ?? email, role } });
			return made.user.id;
		},
		handle: async (request) => (new URL(request.url).pathname.startsWith(`${basePath}/`) ? instance.handler(request) : null),
	};
}
