import { Field, walk } from "@tbtop/admin";

/** The fields of host Site-settings tabs, through layout nodes; a repeater is one field. */
export function tabFields(nodes: unknown[]): Field[] {
	return nodes.flatMap((node) => (node instanceof Field ? [node] : tabFields(walk.descendants(node))));
}
