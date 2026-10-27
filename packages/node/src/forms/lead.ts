/** Roughly a table cell's worth of text before it wraps badly. */
const FALLBACK_MAX_LENGTH = 60;

const nonEmpty = (value: unknown): string | null => {
	if (typeof value !== "string" && typeof value !== "number" && typeof value !== "boolean") {
		return null;
	}
	const text = scalarText(value).trim();
	return text === "" ? null : text;
};

/** PHP's (string) cast: true is "1", false is "". */
const scalarText = (value: string | number | boolean): string => (typeof value === "boolean" ? (value ? "1" : "") : String(value));

/** mb_substr: cut by code points, so an emoji at the cut is never split. */
const truncate = (text: string): string => {
	const chars = Array.from(text);
	return chars.length > FALLBACK_MAX_LENGTH ? `${chars.slice(0, FALLBACK_MAX_LENGTH - 1).join("")}…` : text;
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
			return truncate(text);
		}
	}
	return null;
}

/** LeadIdentity::name: the payload's `name`, or null. */
export const leadName = (payload: Record<string, unknown>): string | null => nonEmpty(payload.name);

/** LeadIdentity::contact: how to reach the lead, the email, else a phone a custom form collects. */
export const leadContact = (payload: Record<string, unknown>): string | null => nonEmpty(payload.email) ?? nonEmpty(payload.phone);

/** SubmissionFieldFormatter: scalars as text, arrays and objects as JSON (never "[object Object]"). */
export function formatField(value: unknown): string {
	if (value === null || value === undefined) {
		return "";
	}
	if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
		return scalarText(value);
	}
	return typeof value === "object" ? JSON.stringify(value) : String(value);
}

export const formatFields = (payload: Record<string, unknown>): Record<string, string> =>
	Object.fromEntries(Object.entries(payload).map(([key, value]) => [key, formatField(value)]));
