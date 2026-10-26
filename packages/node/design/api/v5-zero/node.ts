import type { Kysely } from "kysely";
import { ADMIN, fixture, html } from "../shared/reference";
import { serve } from "./package";
import { site } from "./site";

// V5 on Node: the whole host. (render is the framework's; a TanStack host leaves it out and calls cms.loadPage in its loader.)
export const boot = async (db: Kysely<unknown>) =>
	serve(site, {
		db,
		seed: fixture,
		admin: ADMIN,
		render: (props) =>
			html(
				props,
				props.page.type === "service"
					? `${props.page.fields.price satisfies number | null} UAH · ${props.page.fields.duration satisfies string | null}`
					: props.page.type === "page"
						? (props.page.fields.tagline satisfies string | null) ?? ""
						: "",
			),
	});
