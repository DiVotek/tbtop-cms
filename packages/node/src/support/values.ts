/** Small value helpers shared by the resolvers: PHP's is_array/BlankValue semantics in TS. */

export type Dict = Record<string, unknown>;

/** A JSON value: what a host framework can serialize into a page (TanStack Start checks it). */
export type Json = string | number | boolean | null | Json[] | { [key: string]: Json };

/** `T` with every `unknown` read as `Json`: the public shape of props built from JSON columns. */
export type Jsonify<T> = unknown extends T
	? Json
	: T extends (infer U)[]
		? Jsonify<U>[]
		: T extends object
			? { [K in keyof T]: Jsonify<T[K]> }
			: T;

export const isDict = (value: unknown): value is Dict =>
	typeof value === "object" && value !== null && !Array.isArray(value);

export const str = (value: unknown): string | null =>
	typeof value === "string" ? value : typeof value === "number" ? String(value) : null;

/** A trimmed non-empty string, else null. */
export function clean(value: unknown): string | null {
	if (typeof value !== "string") {
		return null;
	}
	const trimmed = value.trim();
	return trimmed === "" ? null : trimmed;
}

/** BlankValue::is: null, a blank string, an empty array/object, or a Lexical document of blank paragraphs. */
export function isBlank(value: unknown): boolean {
	if (Array.isArray(value)) {
		return value.length === 0;
	}
	if (isDict(value)) {
		return Object.keys(value).length === 0 || isBlankDocument(value);
	}
	return value === null || value === undefined || (typeof value === "string" && value.trim() === "");
}

function isBlankDocument(document: Dict): boolean {
	const root = document.root;
	const children = isDict(root) ? root.children : null;
	if (!Array.isArray(children)) {
		return false;
	}
	return children.every(
		(paragraph) =>
			isDict(paragraph) &&
			paragraph.type === "paragraph" &&
			(Array.isArray(paragraph.children) ? paragraph.children : []).every(isBlankInline),
	);
}

function isBlankInline(inline: unknown): boolean {
	if (!isDict(inline)) {
		return false;
	}
	if (inline.type === "linebreak") {
		return true;
	}
	const text = typeof inline.text === "string" || typeof inline.text === "number" ? String(inline.text) : "";
	return inline.type === "text" && /^[\s ]*$/u.test(text);
}

/** json_decode($raw, true) of a JSON column; invalid JSON reads as null. */
export function decodeJson(raw: unknown): unknown {
	if (typeof raw !== "string") {
		return raw ?? null;
	}
	try {
		return JSON.parse(raw);
	} catch {
		return null;
	}
}

export const encodeJson = (value: unknown): string | null =>
	value === null || value === undefined ? null : JSON.stringify(value);

/** Laravel's `Y-m-d H:i:s` (UTC) for a Date. */
export function sqlDate(date: Date): string {
	return date.toISOString().slice(0, 19).replace("T", " ");
}

/** A stored `Y-m-d H:i:s` (UTC) or ISO string as a Date; null when absent or invalid. */
export function parseDate(value: unknown): Date | null {
	if (value instanceof Date) {
		return value;
	}
	if (typeof value !== "string" || value === "") {
		return null;
	}
	const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(value) ? `${value.replace(" ", "T")}Z` : value;
	const date = new Date(iso);
	return Number.isNaN(date.getTime()) ? null : date;
}

/** Carbon's toIso8601String()/toAtomString() in UTC: `2026-10-22T10:00:00+00:00`. */
/** The `Y-m-d` day of a moment in an IANA zone, from formatToParts: no locale's date pattern is assumed. */
export function isoDay(date: Date, timeZone: string): string {
	const parts = new Intl.DateTimeFormat("en-US", { timeZone, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(date);
	const part = (type: string) => parts.find((entry) => entry.type === type)?.value ?? "";
	return `${part("year")}-${part("month")}-${part("day")}`;
}

export const atom = (date: Date): string => `${date.toISOString().slice(0, 19)}+00:00`;

export const bool = (value: unknown): boolean => value === true || value === 1 || value === "1";

/** A boolean column's write value: 0/1 binds on every driver (better-sqlite3 rejects booleans). */
export const flag = (value: boolean): 0 | 1 => (value ? 1 : 0);
