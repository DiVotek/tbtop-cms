import { useForm } from "@inertiajs/react";
import { type FormEvent, useState } from "react";
import type { z } from "zod";
import type { contactFormProps } from "../catalog";
import { emitCmsTelemetry, useCmsTelemetry } from "../telemetry";

type ContactFormProps = z.infer<typeof contactFormProps>;

export function ContactForm({ props }: { props: ContactFormProps }) {
    const telemetry = useCmsTelemetry();
    const initial: Record<string, string> = Object.fromEntries(
        props.fields.map((field): [string, string] => [field.name, ""]),
    );
    const form = useForm<Record<string, string>>(initial);
    const [sent, setSent] = useState(false);

    function submit(event: FormEvent): void {
        event.preventDefault();
        emitCmsTelemetry(telemetry, "form_submit");
        form.post(props.action, {
            preserveScroll: true,
            onSuccess: () => {
                setSent(true);
                form.reset();
                emitCmsTelemetry(telemetry, "form_success");
            },
            onError: () => emitCmsTelemetry(telemetry, "form_error"),
        });
    }

    if (sent) {
        return (
            <p data-testid="contact-success" className="cms-contact-form__success">
                {props.successMessage}
            </p>
        );
    }

    return (
        <form data-testid="contact-form" className="cms-contact-form" onSubmit={submit}>
            {form.errors.form ? (
                <p role="alert" data-testid="contact-error" className="cms-contact-form__error">
                    {form.errors.form}
                </p>
            ) : null}
            {props.heading ? <h2>{props.heading}</h2> : null}
            {props.fields.map((field) => (
                <label key={field.name} className="cms-contact-form__field">
                    <span>{field.label}</span>
                    {field.type === "textarea" ? (
                        <textarea
                            name={field.name}
                            required={field.required}
                            value={form.data[field.name] ?? ""}
                            onChange={(event) => form.setData(field.name, event.target.value)}
                        />
                    ) : (
                        <input
                            type={field.type}
                            name={field.name}
                            required={field.required}
                            value={form.data[field.name] ?? ""}
                            onChange={(event) => form.setData(field.name, event.target.value)}
                        />
                    )}
                    {form.errors[field.name] ? <em>{form.errors[field.name]}</em> : null}
                </label>
            ))}
            <button type="submit" disabled={form.processing}>
                {props.submitLabel}
            </button>
        </form>
    );
}
