import type { Kysely } from "kysely";
import { seedFixtures } from "../../../src";
import { renderPage } from "../kit/serve";
import { ADMIN, fixture, html } from "../shared/reference";
import { company } from "./company";
import type { CmsPlugin } from "./define";
import { betterAuthPlugin, createCms, hero } from "./package";

export function site(db: Kysely<unknown>, platform: CmsPlugin<[]>[] = []) {
	const cms = createCms({ db, plugins: [hero(), company, betterAuthPlugin(), ...platform] });
	return {
		cms,
		boot: async () => {
			await cms.migrate();
			await seedFixtures(cms, fixture);
			await cms.users.ensure(ADMIN.email, "admin", { password: ADMIN.password });
		},
		fetch: async (request: Request) =>
			(await cms.route(request)) ??
			renderPage(cms, request, (props) =>
				html(
					props,
					props.page.type === "service"
						? `${props.page.fields.price satisfies number | null} UAH · ${props.page.fields.duration satisfies string | null}`
						: props.page.type === "page"
							? (props.page.fields.tagline satisfies string | null) ?? ""
							: "",
				),
			),
	};
}

export async function boot(db: Kysely<unknown>) {
	const app = site(db);
	await app.boot();
	return app;
}
