import { Field, S, walk } from "@tbtop/admin";
import type { CmsConfig } from "../config";

/** The fields of host Site-settings tabs, through layout nodes; a repeater is one field. */
export function tabFields(nodes: unknown[]): Field[] {
	return nodes.flatMap((node) => (node instanceof Field ? [node] : tabFields(walk.descendants(node))));
}

const hostMedia = new WeakMap<object, string[]>();

/** The `site.<name>` keys of the host tabs' upload and media fields: the frontend gets their URLs. */
export function hostMediaKeys(config: Pick<CmsConfig, "siteSettings">): string[] {
	const tabs = config.siteSettings;
	if (tabs === undefined) {
		return [];
	}
	const known = hostMedia.get(tabs);
	if (known !== undefined) {
		return known;
	}
	const keys = tabFields(tabs.tabs(new S()).flatMap((tab) => tab.children))
		.filter((field) => ["upload", "media"].includes(field.toNode().kind))
		.map((field) => `site.${field.name}`);
	hostMedia.set(tabs, keys);
	return keys;
}
