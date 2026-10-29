import { type FormEvent, useState } from "react";

export type CmsFormState = {
	status: "idle" | "sending" | "sent" | "failed";
	/** Field name to messages; `form` holds an error that belongs to no field. */
	errors: Record<string, string[]>;
};

/**
 * Posts a CMS form block to its `action` as JSON and tracks the answer:
 * `{ ok }`, 422 `{ errors }` or 500 `{ errors: { form } }`. Spread
 * `formProps` on the `<form>`: without JS the browser posts it and the
 * server answers with a 303 back to the page. A failure with no answer from
 * the server (network, CORS, a non-JSON page) lands in `errors.form`.
 */
const UNREACHABLE = "The form could not be sent. Check your connection and try again.";

export function useCmsForm(action: string) {
	const [state, setState] = useState<CmsFormState>({ status: "idle", errors: {} });
	/** Posts `data` (a controlled form's values) and resolves true once the server accepted it. */
	async function submit(data: Record<string, unknown>): Promise<boolean> {
		setState({ status: "sending", errors: {} });
		const response = await fetch(action, {
			method: "POST",
			headers: { "Content-Type": "application/json", Accept: "application/json" },
			body: JSON.stringify(data),
		}).catch(() => null);
		const answer = (await response?.json().catch(() => null)) as { ok?: boolean; errors?: Record<string, string[]> } | null;
		if (answer?.ok === true) {
			setState({ status: "sent", errors: {} });
			return true;
		}
		setState({ status: "failed", errors: answer?.errors ?? { form: [UNREACHABLE] } });
		return false;
	}
	async function onSubmit(event: FormEvent<HTMLFormElement>) {
		event.preventDefault();
		await submit(Object.fromEntries(new FormData(event.currentTarget)));
	}
	/** A client-side check's message on a field (or `form`), shown like the server's. */
	function setError(field: string, message: string) {
		setState((current) => ({ status: "failed", errors: { ...current.errors, [field]: [message] } }));
	}
	/** Drops one field's (or `form`'s) messages, e.g. once the visitor edits it; with none left the form is idle again. */
	function clearError(field: string) {
		setState((current) => {
			if (!(field in current.errors)) {
				return current;
			}
			const { [field]: _cleared, ...errors } = current.errors;
			return { status: Object.keys(errors).length === 0 && current.status === "failed" ? "idle" : current.status, errors };
		});
	}
	/** Back to idle with no errors, e.g. to send the form again. */
	function reset() {
		setState({ status: "idle", errors: {} });
	}
	return { ...state, submit, setError, clearError, reset, formProps: { method: "post", action, onSubmit, noValidate: true } as const };
}
