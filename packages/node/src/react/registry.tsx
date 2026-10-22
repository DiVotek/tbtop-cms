import { defineCatalog } from "@json-render/core";
import { schema } from "@json-render/react/schema";
import type { ReactNode } from "react";
import { z } from "zod";
import type { Catalog } from "../blocks/catalog";
import { ContactForm } from "./blocks/ContactForm";
import { Hero } from "./blocks/Hero";

const fragment = { props: z.object({}), slots: ["default"], description: "Renders its child blocks in order." };

/**
 * The browser half of a block catalog: `defineBlocks(...)` turned into a
 * json-render catalog, plus the `Fragment` kind every CMS spec's root uses.
 * Pass the result and your components to json-render's `defineRegistry`.
 */
export function cmsCatalog<const C extends Catalog>(blocks: C) {
	const components = Object.fromEntries(
		Object.entries(blocks).map(([kind, meta]) => [kind, { props: meta.props, description: meta.description ?? "" }]),
	) as { [K in keyof C]: { props: C[K]["props"]; description: string } };
	return defineCatalog(schema, { components: { Fragment: fragment, ...components }, actions: {} });
}

/** The built-in kinds' components, for `defineRegistry(catalog, { components: { ...cmsComponents, ...mine } })`. */
export const cmsComponents = {
	Fragment: ({ children }: { children?: ReactNode }) => <>{children}</>,
	Hero,
	ContactForm,
};
