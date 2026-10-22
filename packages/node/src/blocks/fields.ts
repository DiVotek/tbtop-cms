import { Field, Repeater } from "@tbtop/admin";
import { isBlank, isDict } from "../support/values";
import type { ResolveContext } from "./context";

type Resolve = (value: unknown, ctx: ResolveContext) => unknown;

const blankToNull = (value: string): string | null => (value === "" ? null : value);

/** The top-level fields of a form definition, layout wrappers unwrapped. */
export function formFields(nodes: unknown[]): Field[] {
	const out: Field[] = [];
	for (const node of nodes) {
		if (node instanceof Field) {
			out.push(node);
		}
	}
	return out;
}

/**
 * FormResolver: stored input to frontend props, by field kind. Richtext
 * becomes HTML, media a URL, link `{href, type, label}`, numbers numbers,
 * booleans booleans, repeaters rows without blank ones; everything else a
 * trimmed string or null.
 */
export async function resolveForm(fields: unknown[], input: Record<string, unknown>, ctx: ResolveContext): Promise<Record<string, unknown>> {
	const out: Record<string, unknown> = {};
	for (const field of formFields(fields)) {
		out[field.name] = await ruleFor(field)(input[field.name] ?? null, ctx);
	}
	return out;
}


function ruleFor(field: Field): Resolve {
	const node = field.toNode();
	const kind = node.kind;
	const multiple = node.options.multiple === true;
	if (field instanceof Repeater) {
		return (value, ctx) => rows(field.childFields(), value, ctx);
	}
	if (field.childFields().length > 0) {
		return (value) => value;
	}
	switch (kind) {
		case "richtext":
			return (value, ctx) => (isBlank(value) ? null : blankToNull(ctx.richtextHtml(value)));
		case "media":
		case "upload":
			if (multiple) {
				return (value, ctx) => (Array.isArray(value) ? value : []).map((ref) => mediaRef(ref, ctx)).filter((url) => url !== null);
			}
			return (value, ctx) => mediaRef(value, ctx);
		case "link":
			return (value, ctx) => ctx.link(value);
		case "number":
		case "slider":
			return (value) => {
				if (typeof value === "number") {
					return value;
				}
				return typeof value === "string" && value.trim() !== "" && Number.isFinite(Number(value)) ? Number(value) : null;
			};
		case "boolean":
		case "checkbox":
			return (value) => value === true || value === 1 || ["1", "true", "on", "yes"].includes(String(value).toLowerCase());
		case "daterange":
			return (value) => ({
				from: isDict(value) && typeof value.from === "string" ? blankToNull(value.from.trim()) : null,
				to: isDict(value) && typeof value.to === "string" ? blankToNull(value.to.trim()) : null,
			});
		case "keyvalue":
			return (value) =>
				Object.fromEntries(
					Object.entries(isDict(value) ? value : {})
						.filter(([, v]) => ["string", "number", "boolean"].includes(typeof v))
						.map(([k, v]) => [k, String(v)]),
				);
	}
	if (kind === "checkboxlist" || kind === "tags" || (multiple && ["select", "togglebuttons"].includes(kind))) {
		return (value) =>
			(Array.isArray(value) ? value : [])
				.map((item) => (["string", "number", "boolean"].includes(typeof item) ? blankToNull(String(item).trim()) : null))
				.filter((item) => item !== null);
	}
	return (value) => (["string", "number", "boolean"].includes(typeof value) ? blankToNull(String(value).trim()) : null);
}

function mediaRef(value: unknown, ctx: ResolveContext): string | null {
	return typeof value === "string" || typeof value === "number" ? ctx.mediaUrl(String(value)) : null;
}

async function rows(fields: unknown[], value: unknown, ctx: ResolveContext): Promise<Record<string, unknown>[]> {
	const out: Record<string, unknown>[] = [];
	for (const row of Array.isArray(value) ? value : []) {
		if (!isDict(row)) {
			continue;
		}
		const resolved = await resolveForm(fields, row, ctx);
		if (!Object.values(resolved).every(isEmpty)) {
			out.push(resolved);
		}
	}
	return out;
}

function isEmpty(value: unknown): boolean {
	if (Array.isArray(value)) {
		return value.length === 0;
	}
	if (isDict(value)) {
		return Object.keys(value).length === 0 || Object.values(value).every((v) => v === null);
	}
	return value === null || value === false;
}
