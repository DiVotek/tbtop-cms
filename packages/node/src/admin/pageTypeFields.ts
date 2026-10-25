import { Cond, Field, S } from "@tbtop/admin";
import { bindCmsFields, PagesField } from "../blocks/cmsFields";
import type { Cms } from "../cms";
import type { Page } from "../content/page";
import type { PageType } from "../content/pageTypes";
import { type Dict, isDict } from "../support/values";
import { fieldBinding } from "./linkFields";
import { editorValues } from "./support";

const PREFIX = "field_";

/** The page's base type, then each subtype of it (and the stored one), paired with the key that picks it. */
type Section = { type: PageType; subtype: string | null };

/**
 * PageEditTypeFields: the General tab's sections of the type's custom fields
 * (definePageType({ form })), one for the base type, then one per subtype
 * shown only while that subtype is picked. Base fields are renamed
 * `field_{name}`, a subtype's `field_{subtype}__{name}`; fold() reads them
 * back into the page's `fields` column under the bare name.
 */
export function typeFieldSections(s: S, cms: Cms, page: Page): unknown[] {
	const all = sections(cms, page);
	const redeclared = redeclaredBy(all);
	const nodes: unknown[] = [];
	for (const { type, subtype } of all) {
		const fields = type.form(s);
		if (fields.length === 0) {
			continue;
		}
		for (const field of fields) {
			if (field instanceof Field) {
				prepare(field, subtype, redeclared.get(field.name) ?? [], page);
			}
		}
		nodes.push(s.section({ title: type.label, ...(subtype === null ? {} : { hiddenIf: Cond.neq("subtype", subtype) }) }, bindCmsFields(fields, fieldBinding(cms))));
	}
	return nodes;
}

/** The stored `fields` as form values of the sections' inputs (PageEditTypeFields::recordValues). */
export function typeFieldRecord(cms: Cms, page: Page): Dict {
	const stored = isDict(page.fields) ? page.fields : {};
	const out: Dict = {};
	for (const { type, subtype } of sections(cms, page)) {
		const fields = type.form(new S()).filter((field): field is Field => field instanceof Field);
		for (const [name, value] of Object.entries(editorValues(fields, stored))) {
			out[inputName(subtype, name)] = value;
		}
	}
	return out;
}

/**
 * PageEditTypeFields::fold: the stored `fields` with the base section's and
 * the picked subtype's submitted values laid over them. A name both declare
 * is the subtype's; anything else in `fields` is carried through.
 */
export function foldTypeFields(cms: Cms, page: Page, form: Dict): Dict | null {
	const all = sections(cms, page);
	if (all.every(({ type }) => names(type).length === 0)) {
		return null;
	}
	const fields: Dict = isDict(page.fields) ? { ...page.fields } : {};
	const picked = "subtype" in form ? (typeof form.subtype === "string" ? form.subtype : "") : (page.subtype ?? "");
	const inputs = new Map<string, string>();
	for (const { type, subtype } of all) {
		if (subtype !== null && subtype !== picked) {
			continue;
		}
		for (const name of names(type)) {
			inputs.set(name, inputName(subtype, name));
		}
	}
	for (const [name, input] of inputs) {
		if (input in form) {
			fields[name] = form[input];
		}
	}
	return fields;
}

/**
 * Renames the field to its input name. A subtype's `required` holds only
 * while that subtype is picked; a base field a picked subtype re-declares
 * yields to it (hidden, not required). A pages field does not offer the
 * page itself.
 */
function prepare(field: Field, subtype: string | null, redeclaredBy: string[], page: Page): void {
	const original = field.name;
	Object.defineProperty(field, "name", { value: inputName(subtype, original) });
	if (field instanceof PagesField) {
		field.exceptPage(page.id);
	}
	if (subtype !== null) {
		gateRequired(field, `required_if:subtype,${subtype}`);
		return;
	}
	if (redeclaredBy.length > 0) {
		gateRequired(field, `required_unless:subtype,${redeclaredBy.join(",")}`);
		field.hiddenIf(Cond.in("subtype", redeclaredBy));
	}
}

/** Swaps `required` for `rule` in the field's rules and each locale's (PrefixedField::gateRules). */
function gateRequired(field: Field, rule: string): void {
	const swap = (rules: string[]) => rules.map((entry) => (entry === "required" ? rule : entry));
	(field as unknown as { replaceRules(rules: string[]): void }).replaceRules(swap(field.ruleEntries()));
	for (const [locale, rules] of Object.entries(field.localeRuleEntries())) {
		field.rulesForLocale(locale, swap(rules));
	}
}

function sections(cms: Cms, page: Page): Section[] {
	const types = cms.pageTypes;
	if (!types.has(page.type)) {
		return [];
	}
	const out: Section[] = [{ type: types.get(page.type), subtype: null }];
	const subtypes = types.subtypesOf(page.type);
	if (page.subtype !== null && page.subtype !== "" && types.has(page.subtype) && !subtypes.some((type) => type.key === page.subtype)) {
		subtypes.push(types.get(page.subtype));
	}
	for (const type of subtypes) {
		out.push({ type, subtype: type.key });
	}
	return out;
}

/** Base field name → the subtypes whose form declares it again. */
function redeclaredBy(all: Section[]): Map<string, string[]> {
	const base = new Set(all.filter((section) => section.subtype === null).flatMap((section) => names(section.type)));
	const out = new Map<string, string[]>();
	for (const { type, subtype } of all) {
		if (subtype === null) {
			continue;
		}
		for (const name of names(type).filter((entry) => base.has(entry))) {
			out.set(name, [...(out.get(name) ?? []), subtype]);
		}
	}
	return out;
}

const names = (type: PageType): string[] =>
	type
		.form(new S())
		.filter((field): field is Field => field instanceof Field)
		.map((field) => field.name);

const inputName = (subtype: string | null, name: string): string => (subtype === null ? `${PREFIX}${name}` : `${PREFIX}${subtype}__${name}`);
