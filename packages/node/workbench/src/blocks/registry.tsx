import { defineCatalog } from "@json-render/core";
import { defineRegistry } from "@json-render/react";
import { schema } from "@json-render/react/schema";
import { cmsBlocks, type PropsOf } from "@tbtop/cms/catalog";
import { type FormEvent, useState } from "react";
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

type FormState = { status: "idle" | "sending" | "sent" | "failed"; errors: Record<string, string[]> };

// Progressive: without JS the browser posts the form and the server answers
// with a 303 back to the page; with JS it posts JSON and shows the answer here.
function ContactForm({ props }: { props: PropsOf<typeof cmsBlocks, "ContactForm"> }) {
	const [state, setState] = useState<FormState>({ status: "idle", errors: {} });
	async function submit(event: FormEvent<HTMLFormElement>) {
		event.preventDefault();
		setState({ status: "sending", errors: {} });
		const body = Object.fromEntries(new FormData(event.currentTarget));
		const response = await fetch(props.action, {
			method: "POST",
			headers: { "Content-Type": "application/json", Accept: "application/json" },
			body: JSON.stringify(body),
		}).catch(() => null);
		const answer = (await response?.json().catch(() => null)) as { ok?: boolean; errors?: Record<string, string[]> } | null;
		setState(answer?.ok === true ? { status: "sent", errors: {} } : { status: "failed", errors: answer?.errors ?? {} });
	}
	if (state.status === "sent") {
		return (
			<section data-testid="contact-form">
				<h2>{props.heading}</h2>
				<p role="status">{props.successMessage}</p>
			</section>
		);
	}
	return (
		<form data-testid="contact-form" method="post" action={props.action} onSubmit={submit} noValidate>
			<h2>{props.heading}</h2>
			{props.fields.map((field) => (
				<label key={field.name}>
					<span>{field.label}</span>
					{field.type === "textarea" ? (
						<textarea name={field.name} required={field.required} />
					) : (
						<input type={field.type} name={field.name} required={field.required} />
					)}
					{state.errors[field.name]?.map((error) => (
						<small key={error} role="alert">
							{error}
						</small>
					))}
				</label>
			))}
			{props.honeypotField ? (
				// Hidden from people and screen readers; a bot that fills it gets a fake success.
				<input type="text" name={props.honeypotField} tabIndex={-1} autoComplete="off" aria-hidden="true" style={{ position: "absolute", left: "-10000px" }} />
			) : null}
			{state.errors.form?.map((error) => (
				<p key={error} role="alert">
					{error}
				</p>
			))}
			<button type="submit" disabled={state.status === "sending"}>
				{props.submitLabel}
			</button>
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
