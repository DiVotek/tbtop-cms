import { Link } from "@tanstack/react-router";
import type { JournalIndex } from "../server/blog";

export function JournalIndexView({ index, basePath }: { index: JournalIndex; basePath: string }) {
	return (
		<section data-testid="journal-index" className="lumen-container py-10">
			<div className="mb-8 flex flex-wrap gap-2">
				<TagLink basePath={basePath} tag={undefined} active={!basePath.includes("tag=")}>
					All
				</TagLink>
				{index.allTags.map((tag) => (
					<TagLink key={tag} basePath={basePath} tag={tag} active={basePath.includes(`tag=${tag}`)}>
						{tag}
					</TagLink>
				))}
			</div>
			<ul className="grid gap-6">
				{index.posts.map((post) => (
					<li key={post.id} className="rounded-2xl border border-lumen-line bg-lumen-card p-6">
						<div className="flex flex-wrap items-center gap-2 text-xs text-lumen-muted">
							{post.publishedLabel ? <time dateTime={post.publishedLabel}>{post.publishedLabel}</time> : null}
							{post.readingMinutes ? <span>{post.readingMinutes} min read</span> : null}
						</div>
						<h2 className="font-display mt-2 text-2xl font-semibold">
							<Link to="/$" params={{ _splat: new URL(post.url, "http://x").pathname.slice(1) }}>
								{post.name}
							</Link>
						</h2>
						{post.summary ? <p className="mt-2 text-lumen-muted">{post.summary}</p> : null}
						<div className="mt-3 flex flex-wrap gap-2">
							{post.tags.map((tag) => (
								<span key={tag} className="rounded-full bg-lumen-accent-soft px-2 py-0.5 text-xs font-medium text-lumen-accent">
									{tag}
								</span>
							))}
						</div>
					</li>
				))}
			</ul>
			{index.totalPages > 1 ? (
				<nav aria-label="Pagination" className="mt-10 flex items-center justify-center gap-4 text-sm">
					{index.page > 1 ? (
						<Link to="/$" params={{ _splat: pagePath(basePath, index.page - 1) }} search={searchParams(basePath, index.page - 1)}>
							Previous
						</Link>
					) : null}
					<span>
						Page {index.page} of {index.totalPages}
					</span>
					{index.page < index.totalPages ? (
						<Link to="/$" params={{ _splat: pagePath(basePath, index.page + 1) }} search={searchParams(basePath, index.page + 1)}>
							Next
						</Link>
					) : null}
				</nav>
			) : null}
		</section>
	);
}

function TagLink({ basePath, tag, active, children }: { basePath: string; tag?: string; active: boolean; children: string }) {
	const path = new URL(basePath, "http://x").pathname.slice(1);
	const search = tag ? { tag, page: 1 } : {};
	return (
		<Link
			to="/$"
			params={{ _splat: path }}
			search={search}
			className={`rounded-full px-3 py-1 text-xs font-medium ${active ? "bg-lumen-accent text-white" : "border border-lumen-line"}`}
		>
			{children}
		</Link>
	);
}

function pagePath(basePath: string, page: number): string {
	return new URL(basePath, "http://x").pathname.slice(1);
}

function searchParams(basePath: string, page: number): Record<string, unknown> {
	const url = new URL(basePath, "http://x");
	const tag = url.searchParams.get("tag");
	return tag ? { tag, page } : { page };
}
