/** Roughly a table cell's worth of text before it wraps badly. */
const FALLBACK_MAX_LENGTH = 60;

const nonEmpty = (value: unknown): string | null => {
	if (typeof value !== "string" && typeof value !== "number" && typeof value !== "boolean") {
		return null;
	}
	const text = String(value).trim();
	return text === "" ? null : text;
};

/**
 * LeadIdentity: "Name · email", either alone, else the first non-empty
 * scalar value (truncated), else null. A custom form's payload keys are not
 * a contract, so this degrades for any shape.
 */
export function leadIdentity(payload: Record<string, unknown>): string | null {
	const name = nonEmpty(payload.name);
	const email = nonEmpty(payload.email);
	if (name !== null && email !== null) {
		return `${name} · ${email}`;
	}
	if (name !== null || email !== null) {
		return name ?? email;
	}
	for (const value of Object.values(payload)) {
		const text = nonEmpty(value);
		if (text !== null) {
			return text.length > FALLBACK_MAX_LENGTH ? `${text.slice(0, FALLBACK_MAX_LENGTH - 1)}…` : text;
		}
	}
	return null;
}

/** SubmissionFieldFormatter: scalars as text, arrays and objects as JSON (never "[object Object]"). */
export function formatField(value: unknown): string {
	if (value === null || value === undefined) {
		return "";
	}
	return typeof value === "object" ? JSON.stringify(value) : String(value);
}

export const formatFields = (payload: Record<string, unknown>): Record<string, string> =>
	Object.fromEntries(Object.entries(payload).map(([key, value]) => [key, formatField(value)]));
