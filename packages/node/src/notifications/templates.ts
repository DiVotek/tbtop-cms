import { Liquid } from "liquidjs";
import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import type { Format, Rendered } from "./types";

/**
 * Merchant overrides are Liquid: `{{ pageName }}`, `{% for field in fields %}`,
 * output escaped. No filesystem, so it renders on Workers too.
 */
const liquid = new Liquid({ outputEscape: "escape", strictFilters: true, ownPropertyOnly: true });

/**
 * A row the PHP package wrote holds Blade (`{{ $x }}`, `@foreach`, `{!! !!}`),
 * which this side cannot run: the built-in template answers instead.
 */
export const looksLikeBlade = (source: string): boolean => /\{\{\s*\$|\{!!|@(section|foreach|if|endif|endforeach|php)\b/.test(source);

/**
 * Text PHP would execute if it compiled this row as Blade: a PHP open tag,
 * any `@directive(…)`, or a call inside `{{ }}`. Wider than looksLikeBlade,
 * so the admin never writes a row that runs code on the PHP side.
 */
export const runsAsPhp = (source: string): boolean =>
	looksLikeBlade(source) || /<\?|@[A-Za-z_]\w*\s*\(|@(unless|endunless|else|elseif|for|endfor|while|endwhile|switch|verbatim)\b|\{\{[^}]*\(/.test(source);

export type Override = { subject: string; body: string };

/** DatabaseTemplateStore: the stored override of a type's format in a locale, or null. */
export async function overrideFor(db: Kysely<CmsDatabase>, type: string, format: Format, locale: string): Promise<Override | null> {
	const row = await db
		.selectFrom("tbtop_notification_templates")
		.select(["subject", "body"])
		.where("type", "=", type)
		.where("format", "=", format)
		.where("locale", "=", locale)
		.executeTakeFirst();
	if (row === undefined || row.body.trim() === "") {
		return null;
	}
	if (looksLikeBlade(row.body) || looksLikeBlade(row.subject)) {
		console.warn("notifications.template_blade_ignored", { type, format, locale });
		return null;
	}
	return row;
}

/**
 * Renders an override with the payload as its variables. The subject is
 * plain text on one line: a visitor's line break never reaches a header.
 */
export async function renderOverride(override: Override, data: Record<string, unknown>): Promise<Rendered> {
	const [subject, body] = await Promise.all([liquid.parseAndRender(override.subject, data), liquid.parseAndRender(override.body, data)]);
	return { subject: decode(subject).replace(/\s+/g, " ").trim(), body, data };
}

/** Parses a template without rendering it: the admin rejects a broken override before saving it. */
export function templateError(source: string): string | null {
	try {
		liquid.parse(source);
		return null;
	} catch (error) {
		return error instanceof Error ? error.message : String(error);
	}
}

/** Escaping for the built-in HTML templates. */
export const escapeHtml = (value: unknown): string =>
	String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char] ?? char);

const decode = (text: string): string =>
	text.replace(/&(amp|lt|gt|quot|#39);/g, (entity) => ({ "&amp;": "&", "&lt;": "<", "&gt;": ">", "&quot;": '"', "&#39;": "'" })[entity] ?? entity);
