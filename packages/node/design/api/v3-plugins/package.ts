import { runtimeFromEnv } from "../kit/cloudflare";
import type { Kysely } from "kysely";
import { hero as heroBlock } from "../kit/hero";
import type { Env } from "../shared/workers";
import { type BlockImpl, type BlockMeta, type Cms, type CmsDatabase, createCms as createCore, implementBlocks, type PageTypeDef } from "../../../src";
import { type DefaultAuth, type DefaultAuthOptions, defaultAuth } from "../kit/auth";
import { withCmsFields } from "../kit/cmsFields";
import { cmsRoutes, createCmsAdmin, migrateAll } from "../kit/serve";
import { type CmsPlugin, definePlugin, type Runtime } from "./define";

export { definePlugin };

/** `@tbtop/cms/blocks`: the opt-in built-ins, one plugin each. */
export const hero = () => definePlugin({ name: "hero", blocks: { Hero: heroBlock } });

/** `@tbtop/cms/cloudflare`: the bindings as runtime contributions (KV cache, R2 disk, D1 for Better Auth, secrets). */
export const cloudflare = (env: Env, options: { mediaUrl?: string } = {}) =>
	definePlugin({
		name: "cloudflare",
		// Everything but db: the plugin list is built after the Kysely instance.
		runtime: (({ db: _db, ...rest }) => rest)(runtimeFromEnv(env, options)),
	});

// biome-ignore lint/suspicious/noExplicitAny: a plugin list of any page types and APIs.
type AnyPlugin = CmsPlugin<any, any>;
type TypesOf<P extends readonly AnyPlugin[]> = P[number] extends infer X ? (X extends { pageTypes?: infer PT } ? (NonNullable<PT> extends readonly (infer D extends PageTypeDef)[] ? D : never) : never) : never;
type Intersect<U> = (U extends unknown ? (x: U) => void : never) extends (x: infer I) => void ? I : never;
type ApiOf<P extends readonly AnyPlugin[]> = Intersect<P[number] extends infer X ? (X extends { setup?: (ctx: never) => { api?: infer A } } ? (unknown extends A ? never : NonNullable<A>) : never) : never>;

/** `@tbtop/cms/better-auth`: Better Auth from `db`, plus `cms.users.ensure`. */
export function betterAuthPlugin(options: Omit<DefaultAuthOptions, "secret" | "baseURL" | "database"> = {}) {
	return definePlugin({
		name: "better-auth",
		auth: (db, runtime) =>
			defaultAuth(db, {
				...options,
				...(runtime.secret === undefined ? {} : { secret: runtime.secret }),
				...(runtime.baseURL === undefined ? {} : { baseURL: runtime.baseURL }),
				...(runtime.authDatabase === undefined ? {} : { database: runtime.authDatabase }),
			}),
		setup: ({ auth }) => ({
			api: { users: { ensure: (email: string, role: string, o: { password: string; name?: string }) => (auth as DefaultAuth).ensureUser(email, role, o) } },
		}),
	});
}

/** Collects every plugin's contributions into the core config; a duplicate block kind, page type or runtime key (cache, disk, secret…) throws, naming both plugins. */
export function createCms<const P extends readonly AnyPlugin[]>(options: { db: Kysely<unknown>; plugins: P }) {
	const { db, plugins } = options;
	const owner = new Map<string, string>();
	const claim = (what: string, key: string, plugin: string) => {
		const taken = owner.get(`${what}:${key}`);
		if (taken !== undefined) {
			throw new Error(`${what} [${key}] comes from both plugin [${taken}] and [${plugin}].`);
		}
		owner.set(`${what}:${key}`, plugin);
	};
	const runtime: Runtime = {};
	for (const plugin of plugins) {
		for (const [key, value] of Object.entries(plugin.runtime ?? {})) {
			claim("Runtime", key, plugin.name);
			Object.assign(runtime, { [key]: value });
		}
	}
	const authPlugins = plugins.filter((p) => p.auth !== undefined);
	if (authPlugins.length > 1) {
		throw new Error(`Only one auth plugin: ${authPlugins.map((p) => p.name).join(", ")}.`);
	}
	const auth = authPlugins[0]?.auth?.(db, runtime) ?? null;
	const catalog: Record<string, BlockMeta> = {};
	const impls: Record<string, BlockImpl> = {};
	for (const plugin of plugins) {
		for (const [kind, b] of Object.entries(plugin.blocks ?? {})) {
			claim("Block", kind, plugin.name);
			catalog[kind] = { props: b.props, label: b.label, description: b.description, category: b.category, placements: b.placements };
			impls[kind] = { form: withCmsFields(b.form), resolve: b.resolve, toSchema: b.toSchema, submit: b.submit };
		}
		for (const type of plugin.pageTypes ?? []) {
			claim("Page type", type.key, plugin.name);
		}
	}
	const extenders = plugins.flatMap((p) => (p.seo?.extend === undefined ? [] : [p.seo.extend]));
	const tabs = plugins.flatMap((p) => p.settings?.site ?? []);
	const core = createCore({
		...Object.assign({}, ...plugins.map((p) => p.core ?? {})),
		db: db as Kysely<CmsDatabase>,
		blocks: implementBlocks(catalog, impls),
		pageTypes: plugins.flatMap((p) => [...(p.pageTypes ?? [])]),
		schemaTypes: plugins.flatMap((p) => p.schemaTypes ?? []),
		seo: { extend: async (nodes, ctx) => extenders.reduce(async (acc, extend) => extend(await acc, ctx), Promise.resolve(nodes)) },
		siteSettings: { tabs: (s) => tabs.map((tab) => ({ label: tab.label, children: withCmsFields(tab.fields)(s) })) },
		secretSettings: plugins.flatMap((p) => p.settings?.secret ?? []),
		...(auth === null ? {} : { auth: auth.adapter }),
		...(runtime.cache === undefined ? {} : { cache: { store: runtime.cache } }),
	}) as unknown as Cms<TypesOf<P>[]>;
	const admin = createCmsAdmin(core, runtime.disk === undefined ? {} : { disks: { public: runtime.disk } });
	const setups = plugins.map((p) => p.setup?.({ cms: core, auth }) ?? {});
	const base = cmsRoutes(core, admin, auth);
	// A plugin's api may add members, never replace a core one (tasks, route, migrate…) or another plugin's.
	setups.forEach((setup, i) => {
		for (const key of Object.keys(setup.api ?? {})) {
			if (key in core || key === "route" || key === "migrate") {
				throw new Error(`Plugin [${plugins[i]?.name}] api [${key}] would replace a core member.`);
			}
			claim("Api", key, plugins[i]?.name ?? String(i));
		}
	});
	return Object.assign(core, ...setups.map((s) => s.api ?? {}), {
		route: async (request: Request) => {
			for (const setup of setups) {
				const res = (await setup.route?.(request)) ?? null;
				if (res !== null) return res;
			}
			return base(request);
		},
		migrate: async () => {
			await migrateAll(db, auth);
			for (const setup of setups) await setup.migrate?.();
		},
	}) as Cms<TypesOf<P>[]> & ApiOf<P> & { route(request: Request): Promise<Response | null>; migrate(): Promise<void> };
}
