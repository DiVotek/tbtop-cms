import type { ErrorPageProps, PageProps } from "@tbtop/cms";
import type { site } from "./site";

// The host's renderer: plain HTML here, a React page in a real host
// (`@tbtop/cms/react` renders `props.render` with the site's registry).

// The second argument types `props.cms.settings` by the site's settings tabs.
type Props = PageProps<typeof site.pageTypes, typeof site.settings>;

const esc = (text: unknown) => String(text ?? "").replace(/[&<>"]/g, (c) => `&#${c.charCodeAt(0)};`);

/** JSON inside <script>: a `</script>` in a page name or a setting must not close the tag. */
const json = (value: unknown) => JSON.stringify(value).replace(/[<>&\u2028\u2029]/g, (c) => `\\u${c.charCodeAt(0).toString(16).padStart(4, "0")}`);

/** A line per page type, from `page.fields`, typed by the site's fields. */
function fieldsLine(page: Props["page"]): string {
	switch (page.type) {
		case "service":
			return `${page.fields.price ?? "?"} UAH · ${page.fields.duration ?? ""}${page.fields.level === "pro" ? " · Pro" : ""}${(page.fields.channels ?? []).map((channel) => ` · ${channel}`).join("")}`;
		case "page":
			return page.fields.tagline ?? "";
		default:
			return "";
	}
}

function blockHtml(type: string, props: Record<string, unknown>): string {
	if (type === "Callback") {
		// A form block's props carry `action` (and the honeypot input's name): it posts there.
		return `<form method="post" action="${esc(props.action)}"><h2>${esc(props.heading)}</h2><input name="name"><input name="phone"><button>${esc(props.button)}</button></form>`;
	}
	return `<section data-kind="${esc(type)}">${esc(JSON.stringify(props))}</section>`;
}

export function render(props: Props): string {
	const blocks = (props.render.elements.$root?.children ?? []).map((id) => props.render.elements[id]);
	return `<!doctype html><html lang="${esc(props.cms.locale)}"><head><meta charset="utf-8"><title>${esc(props.seo.title)}</title>
${props.seo.jsonLd.map((node) => `<script type="application/ld+json">${json(node)}</script>`).join("")}</head>
<body><h1>${esc(props.page.name)}</h1><p class="fields">${esc(fieldsLine(props.page))}</p>
${blocks.map((block) => (block === undefined ? "" : blockHtml(block.type, block.props as Record<string, unknown>))).join("\n")}
<footer>${esc(props.cms.settings.name)} · ${esc(props.cms.settings.phone)} · ${esc(props.cms.settings.address)}</footer>
</body></html>`;
}

export function renderError(props: ErrorPageProps): string {
	return `<!doctype html><title>${esc(props.seo.title)}</title><h1>${esc(props.page.heading ?? props.status)}</h1>`;
}
