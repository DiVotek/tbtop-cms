import type { PropsOf } from "../../blocks/catalog";
import type { cmsBlocks } from "../../blocks/catalog";
import { useCmsForm } from "../useCmsForm";

/**
 * The built-in ContactForm, unstyled (`cms-contact-form*` classes). It posts
 * JSON and shows the field errors or `successMessage`; without JS it is a
 * plain form post. The honeypot input stays out of sight and out of the tab
 * order: a bot that fills it gets a fake success.
 */
export function ContactForm({ props }: { props: PropsOf<typeof cmsBlocks, "ContactForm"> }) {
	const form = useCmsForm(props.action);
	if (form.status === "sent") {
		return (
			<section data-testid="contact-form" className="cms-contact-form">
				{props.heading ? <h2>{props.heading}</h2> : null}
				<p role="status" className="cms-contact-form__success">
					{props.successMessage}
				</p>
			</section>
		);
	}
	return (
		<form data-testid="contact-form" className="cms-contact-form" {...form.formProps}>
			{props.heading ? <h2>{props.heading}</h2> : null}
			{props.fields.map((field) => (
				<label key={field.name} className="cms-contact-form__field">
					<span>{field.label}</span>
					{field.type === "textarea" ? (
						<textarea name={field.name} required={field.required} />
					) : (
						<input type={field.type} name={field.name} required={field.required} />
					)}
					{form.errors[field.name]?.map((error) => (
						<small key={error} role="alert">
							{error}
						</small>
					))}
				</label>
			))}
			{props.honeypotField ? (
				<div aria-hidden="true" style={{ position: "absolute", left: "-10000px" }}>
					<input type="text" name={props.honeypotField} tabIndex={-1} autoComplete="off" />
				</div>
			) : null}
			{form.errors.form?.map((error) => (
				<p key={error} role="alert" className="cms-contact-form__error">
					{error}
				</p>
			))}
			<button type="submit" disabled={form.status === "sending"}>
				{props.submitLabel}
			</button>
		</form>
	);
}
