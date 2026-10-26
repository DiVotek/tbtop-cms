import type { Kysely } from "kysely";
import { seedFixtures } from "../../../src";
import { renderPage } from "../kit/serve";
import { ADMIN, fixture, html } from "../shared/reference";
import { createCms } from "./package";
import { site } from "./site";
import type { Runtime } from "../v2-config/package";

export function app(runtime: Runtime) {
	const cms = createCms(site, runtime);
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
	const a = app({ db });
	await a.boot();
	return a;
}
