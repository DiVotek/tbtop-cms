import type { PageProps } from "@tbtop/cms";

export function ArticleMeta({ page }: { page: PageProps["page"] }) {
	if (page.type !== "article") {
		return null;
	}
	const fields = page.fields as { tags?: string[]; readingMinutes?: number | null };
	const tags = Array.isArray(fields.tags) ? fields.tags : [];
	return (
		<div data-testid="article-meta" className="lumen-container flex flex-wrap gap-2 pb-4 text-sm text-lumen-muted">
			{typeof fields.readingMinutes === "number" ? <span>{fields.readingMinutes} min read</span> : null}
			{tags.map((tag) => (
				<span key={tag} className="rounded-full bg-lumen-accent-soft px-2 py-0.5 text-xs font-medium text-lumen-accent">
					{tag}
				</span>
			))}
		</div>
	);
}
