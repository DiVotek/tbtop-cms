import { isBlank, isDict } from "../support/values";

type FieldLike = { name: string; isTranslatableField(): boolean; childFields(): unknown[] };

const isFieldLike = (value: unknown): value is FieldLike =>
	typeof (value as Partial<FieldLike> | null)?.isTranslatableField === "function";

/** TranslatablePaths::of: dotted paths of the translatable fields, nested ones included. */
export function translatablePaths(fields: unknown[], prefix = ""): string[] {
	const paths: string[] = [];
	for (const field of fields) {
		if (!isFieldLike(field)) {
			continue;
		}
		const path = prefix === "" ? field.name : `${prefix}.${field.name}`;
		if (field.isTranslatableField()) {
			paths.push(path);
		}
		paths.push(...translatablePaths(field.childFields(), path));
	}
	return paths;
}

/**
 * LocaleFlattener::flattenData: every locale map in block data becomes the
 * value for `locale`, else `fallback`, skipping blank ones. A map is a dict
 * whose keys are all known locales, or any dict at a translatable path that
 * has the locale or the fallback as a key.
 */
export function flattenLocales(
	data: Record<string, unknown>,
	locale: string,
	fallback: string,
	locales: string[],
	translatable: string[] | null = null,
): Record<string, unknown> {
	const out = flatten(data, { locale, fallback, locales, translatable }, "", translatable === null);
	return isDict(out) ? out : {};
}

type Scope = { locale: string; fallback: string; locales: string[]; translatable: string[] | null };

function flatten(data: unknown, scope: Scope, path: string, translatable: boolean): unknown {
	if (Array.isArray(data)) {
		return data.map((item) => flatten(item, scope, path, translatable));
	}
	if (!isDict(data) || Object.keys(data).length === 0) {
		return data;
	}
	if (isLocaleMap(data, scope, translatable)) {
		return pick(data, scope);
	}
	const out: Record<string, unknown> = {};
	for (const [key, value] of Object.entries(data)) {
		const child = path === "" ? key : `${path}.${key}`;
		out[key] = flatten(value, scope, child, scope.translatable !== null && scope.translatable.includes(child));
	}
	return out;
}

function pick(data: Record<string, unknown>, { locale, fallback }: Scope): unknown {
	for (const candidate of [locale, fallback]) {
		if (!isBlank(data[candidate])) {
			return data[candidate];
		}
	}
	return data[locale] ?? data[fallback] ?? null;
}

function isLocaleMap(data: Record<string, unknown>, { locale, fallback, locales }: Scope, translatable: boolean): boolean {
	const keys = Object.keys(data);
	if (translatable && (keys.includes(locale) || keys.includes(fallback))) {
		return true;
	}
	return keys.every((key) => locales.includes(key));
}
