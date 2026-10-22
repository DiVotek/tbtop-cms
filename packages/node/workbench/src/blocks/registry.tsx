import { defineCatalog } from "@json-render/core";
import { defineRegistry } from "@json-render/react";
import { schema } from "@json-render/react/schema";
import { cmsBlocks, type PropsOf } from "@tbtop/cms/catalog";
import { z } from "zod";

// The browser half of the block catalog: the shared zod props from
// @tbtop/cms/catalog, turned into a json-render catalog with components.
const catalog = defineCatalog(schema, {
	components: {
		Fragment: { props: z.object({}), slots: ["default"], description: "Renders its child blocks in order." },
		Hero: { props: cmsBlocks.Hero.props, description: cmsBlocks.Hero.description ?? "" },
		ContactForm: { props: cmsBlocks.ContactForm.props, description: cmsBlocks.ContactForm.description ?? "" },
	},
	actions: {},
});

function Hero({ props }: { props: PropsOf<typeof cmsBlocks, "Hero"> }) {
	return (
		<section data-testid="hero">
			<h2>{props.title}</h2>
			{props.subtitle ? <p>{props.subtitle}</p> : null}
			{props.image ? <img src={props.image} alt="" /> : null}
			{props.cta ? <a href={props.cta.href}>{props.cta.label}</a> : null}
		</section>
	);
}

function ContactForm({ props }: { props: PropsOf<typeof cmsBlocks, "ContactForm"> }) {
	return (
		<form data-testid="contact-form" method="post" action={props.action}>
			<h2>{props.heading}</h2>
			{props.fields.map((field) => (
				<label key={field.name}>
					<span>{field.label}</span>
					{field.type === "textarea" ? (
						<textarea name={field.name} required={field.required} />
					) : (
						<input type={field.type} name={field.name} required={field.required} />
					)}
				</label>
			))}
			<button type="submit">{props.submitLabel}</button>
		</form>
	);
}

export const { registry } = defineRegistry(catalog, {
	components: {
		Fragment: ({ children }) => <>{children}</>,
		Hero,
		ContactForm,
	},
});
