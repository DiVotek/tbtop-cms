import type { Kysely } from "kysely";
import { z } from "zod";
import { cmsBlockImpls, type CmsDatabase, createCms, definePageType, identityId, implementBlocks, linkField, seedFixtures, type SchemaTypeDef, baseNode } from "../../../src";
import type { Disk } from "@tbtop/admin";
import type { CmsCacheStore } from "../../../src";
import type { DefaultAuthOptions } from "../kit/auth";
import { renderPage } from "../kit/serve";
import { fixture, html, organization, serviceNode, ADMIN } from "../shared/reference";
import { catalog } from "./catalog";
import { cmsAdmin, cmsBetterAuth, cmsRoutes, migrateAll } from "./package";

// V1 · Canonical composition: each piece is built by its own factory and handed to the next.

const page = definePageType({
	key: "page",
	form: (s) => [s.text("tagline").label("Tagline").translatable()],
	schema: z.object({ tagline: z.string().nullable() }),
});

const service = definePageType({
	key: "service",
	label: "Service",
	schemaTypes: ["Service"],
	form: (s) => [s.number("price").label("Price, UAH"), s.text("duration").label("Duration").translatable()],
	schema: z.object({ price: z.number().nullable(), duration: z.string().nullable() }),
});

const serviceSchema: SchemaTypeDef = {
	key: "Service",
	build: (ctx) => [serviceNode(baseNode(ctx, "Service"), identityId(ctx.site), ctx.page.fields?.price)],
};

/** The host's wiring, shared by the Node server and the Worker: each piece built and handed to the next. */
export function buildSite(db: Kysely<unknown>, options: { auth?: DefaultAuthOptions; cache?: CmsCacheStore; disk?: Disk } = {}) {
	const auth = cmsBetterAuth(db, options.auth);
	const cms = createCms({
		db: db as Kysely<CmsDatabase>,
		blocks: implementBlocks(catalog, {
			Hero: cmsBlockImpls.Hero,
			Promo: { form: (s) => [s.text("title").label("Title").translatable(), linkField("cta").label("Button")] },
		}),
		pageTypes: [page, service],
		schemaTypes: [serviceSchema],
		seo: { extend: (nodes, ctx) => [...nodes.filter((node) => node["@id"] !== identityId(ctx.site)), organization(identityId(ctx.site))] },
		siteSettings: { tabs: (s) => [{ label: "Company", children: [s.text("phone").label("Phone")] }] },
		auth: auth.adapter,
		...(options.cache === undefined ? {} : { cache: { store: options.cache } }),
	});
	const admin = cmsAdmin(cms, options.disk === undefined ? {} : { disks: { public: options.disk } });
	const routes = cmsRoutes(cms, admin, auth);
	return {
		cms,
		/** Migrations, the fixture and the first admin: a deploy step, idempotent. */
		boot: async () => {
			await migrateAll(db, auth);
			await seedFixtures(cms, fixture);
			await auth.ensureUser(ADMIN.email, "admin", { password: ADMIN.password });
		},
		fetch: async (request: Request) =>
			(await routes(request)) ??
			renderPage(cms, request, (props) => {
				// The proof that page.fields is typed per page.type: these lines fail tsc if it is not.
				const extra =
					props.page.type === "service"
						? `${props.page.fields.price satisfies number | null} UAH · ${props.page.fields.duration satisfies string | null}`
						: props.page.type === "page"
							? (props.page.fields.tagline satisfies string | null) ?? ""
							: "";
				return html(props, extra);
			}),
	};
}
