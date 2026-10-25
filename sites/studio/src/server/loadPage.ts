import { createServerFn } from "@tanstack/react-start";
import { getRequest } from "@tanstack/react-start/server";
import type { ErrorPageProps, PageProps } from "@tbtop/cms";
import { journalIndexForCategory, type JournalIndex } from "./blog";
import { site } from "./cms";

export type LoadedPage = PageProps & { journalIndex?: JournalIndex | null };

export type LoadOutcome =
	| { kind: "page"; props: LoadedPage }
	| { kind: "redirect"; location: string; status: number }
	| { kind: "notFound"; props: ErrorPageProps };

export async function loadPageAt(pathname: string, search: string, baseUrl: string): Promise<LoadOutcome> {
	const path = `${pathname}${search}`;
	const target = new URL(path.startsWith("/") ? path : `/${path}`, baseUrl).href;
	const { cms } = await site();
	const result = await cms.loadPage(new Request(target));
	if (result.type === "redirect") {
		return { kind: "redirect", location: result.location, status: result.status };
	}
	if (result.type === "notFound") {
		return { kind: "notFound", props: result.props };
	}
	const props = result.props;
	if (props.page.type === "category") {
		const query = new URLSearchParams(search.startsWith("?") ? search.slice(1) : search);
		const index = await journalIndexForCategory(
			props.page.id,
			props.cms.locale,
			props.cms.defaultLocale,
			Number(query.get("page") ?? "1"),
			query.get("tag") ?? undefined,
		);
		return { kind: "page", props: { ...props, journalIndex: index } };
	}
	return { kind: "page", props };
}

export const loadStudioPage = createServerFn({ method: "GET" })
	.validator((input: { pathname: string; search: string }) => input)
	.handler(async ({ data }) => {
		const caller = getRequest();
		const base = process.env.CMS_URL ?? (caller.url.startsWith("http") ? new URL(caller.url).origin : "http://127.0.0.1:3300");
		const outcome = await loadPageAt(data.pathname, data.search, base);
		if (outcome.kind === "redirect") {
			return { __kind: "redirect" as const, location: outcome.location, status: outcome.status };
		}
		if (outcome.kind === "notFound") {
			return outcome.props;
		}
		return outcome.props;
	});
