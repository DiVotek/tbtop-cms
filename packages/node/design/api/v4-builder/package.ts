import { baseNode, type Cms, identityId, type SchemaContext } from "../../../src";
import { createCms as createFromConfig, type Runtime } from "../v2-config/package";
import type { SeoHelpers, SiteBuilder } from "./define";

export { tbtop } from "./define";

const helpers = (ctx: SchemaContext): SeoHelpers => ({ baseNode: (type) => baseNode(ctx, type), identityId: identityId(ctx.site) });

/** Server half: the builder's definitions plus what they run on. */
// biome-ignore lint/suspicious/noExplicitAny: any builder.
export function createCms<S extends SiteBuilder<any, any>>(site: S, runtime: Runtime) {
	const { config } = site;
	const extenders = config.extenders ?? [];
	const extend = async (nodes: Record<string, unknown>[], ctx: SchemaContext) => {
		let out = nodes;
		for (const fn of extenders) out = await fn(out, ctx, helpers(ctx));
		return out;
	};
	const schemaTypes = Object.fromEntries(
		Object.entries(config.seo?.schemaTypes ?? {}).map(([key, build]) => [key, (ctx: SchemaContext) => (build as (c: SchemaContext, h: SeoHelpers) => Record<string, unknown>[])(ctx, helpers(ctx))]),
	);
	const cms = createFromConfig({ ...config, seo: { schemaTypes, extend } }, runtime);
	return cms as unknown as Omit<typeof cms, keyof Cms> & Cms<S["pageTypes"]>;
}
