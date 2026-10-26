import type { Kysely } from "kysely";
import { seedFixtures } from "../../../src";
import { renderPage } from "../kit/serve";
import { ADMIN, fixture, html } from "../shared/reference";
import config from "./cms.config";
import { createCms } from "./package";

/** The site at runtime: the config plus what it runs on. Shared by the Node server and the Worker. */
export function site(runtime: Parameters<typeof createCms>[1]) {
	const cms = createCms(config, runtime);
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
	const app = site({ db });
	await app.boot();
	return app;
}
