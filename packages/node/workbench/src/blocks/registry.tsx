import { defineRegistry } from "@json-render/react";
import type { PropsOf } from "@tbtop/cms/catalog";
import { cmsCatalog, cmsComponents } from "@tbtop/cms/react";
import { catalog as blocks } from "./catalog";

// The browser half of the block catalog: the shared zod props from
// ./catalog plus the package's built-in components and the workbench's own.
export const catalog = cmsCatalog(blocks);

function Promo({ props }: { props: PropsOf<typeof blocks, "Promo"> }) {
	return (
		<section data-testid="promo">
			{props.title ? <p>{props.title}</p> : null}
			{props.cta ? <a href={props.cta.href}>{props.cta.label}</a> : null}
		</section>
	);
}

export const { registry } = defineRegistry(catalog, { components: { ...cmsComponents, Promo } });
