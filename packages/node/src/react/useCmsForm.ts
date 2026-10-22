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
 * server answers with a 303 back to the page.
 */
export function useCmsForm(action: string) {
	const [state, setState] = useState<CmsFormState>({ status: "idle", errors: {} });
	async function onSubmit(event: FormEvent<HTMLFormElement>) {
		event.preventDefault();
		setState({ status: "sending", errors: {} });
		const body = Object.fromEntries(new FormData(event.currentTarget));
		const response = await fetch(action, {
			method: "POST",
			headers: { "Content-Type": "application/json", Accept: "application/json" },
			body: JSON.stringify(body),
		}).catch(() => null);
		const answer = (await response?.json().catch(() => null)) as { ok?: boolean; errors?: Record<string, string[]> } | null;
		setState(answer?.ok === true ? { status: "sent", errors: {} } : { status: "failed", errors: answer?.errors ?? {} });
	}
	return { ...state, formProps: { method: "post", action, onSubmit, noValidate: true } as const };
}
