import { defineBlock, type RenderProps } from "@tbtop/inertia-admin";
import { useCallback, useState } from "react";
import { useContentLocale } from "./useContentLocale";

interface LocaleCard {
	title: string;
	description: string;
}

interface OgImage {
	url: string;
	alt: string | null;
}

interface OgPreviewOptions {
	/** One resolved title/description pair per content locale — the client picks by active tab. */
	byLocale: Record<string, LocaleCard>;
	/** Shared across locales: og:image is not translatable (see PageForm::seoFields()). */
	image: OgImage | null;
	host: string;
	siteName: string;
	defaultLocale: string;
	emptyTitle: string;
	emptyDescription: string;
	labels: {
		sectionTitle: string;
		noImageHint: string;
	};
}

const OPEN_KEY = "tbtop-cms.ogPreview.open";

/**
 * Same remembered-open behaviour as the SERP preview: a working preference
 * rather than a per-page one, and storage failures fall back to the default
 * rather than breaking the block.
 */
function useRememberedOpen(): [boolean, () => void] {
	const [open, setOpen] = useState<boolean>(() => {
		try {
			return localStorage.getItem(OPEN_KEY) === "1";
		} catch {
			return false;
		}
	});

	const toggle = useCallback(() => {
		setOpen((prev) => {
			const next = !prev;
			try {
				localStorage.setItem(OPEN_KEY, next ? "1" : "0");
			} catch {
				// Preference is a nicety; the panel still works without it.
			}
			return next;
		});
	}, []);

	return [open, toggle];
}

export function OgPreviewBlock({ options }: RenderProps<OgPreviewOptions>) {
	const [open, toggle] = useRememberedOpen();

	// Follows the form's locale tabs, same as the SERP preview block. A locale
	// the server did not resolve (added mid-session) falls back to the default
	// rather than blanking the card.
	const activeLocale = useContentLocale();
	const card =
		(activeLocale === null ? undefined : options.byLocale[activeLocale]) ??
		options.byLocale[options.defaultLocale];

	const title = card?.title ?? "";
	const description = card?.description ?? "";

	return (
		<div className="flex flex-col gap-3 rounded-lg border bg-card px-4 py-3">
			<button
				type="button"
				onClick={toggle}
				aria-expanded={open}
				className="flex w-full items-center justify-between gap-3 text-left"
			>
				<span className="text-sm font-medium text-foreground">{options.labels.sectionTitle}</span>
				<span aria-hidden className="text-xs text-muted-foreground">
					{open ? "▾" : "▸"}
				</span>
			</button>

			{open ? (
				<div className="w-fit max-w-sm overflow-hidden rounded-lg border bg-background">
					{/* 1.91:1 is the aspect ratio every major platform crops a shared
					    link's image to — matching it here is what makes the crop the
					    editor sees the crop that actually ships. */}
					<div className="aspect-[1.91/1] w-full bg-muted">
						{options.image ? (
							<img
								src={options.image.url}
								alt={options.image.alt ?? ""}
								className="h-full w-full object-cover"
							/>
						) : (
							<div className="flex h-full w-full items-center justify-center px-4 text-center text-xs text-muted-foreground">
								{options.labels.noImageHint}
							</div>
						)}
					</div>
					<div className="flex flex-col gap-1 px-3 py-2.5">
						<div className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">
							{options.host}
							{options.siteName ? ` · ${options.siteName}` : ""}
						</div>
						<div className="line-clamp-2 text-sm font-semibold leading-snug text-foreground">
							{title || options.emptyTitle}
						</div>
						<div className="line-clamp-2 text-xs leading-normal text-muted-foreground">
							{description || options.emptyDescription}
						</div>
					</div>
				</div>
			) : null}
		</div>
	);
}

export const ogPreviewBlock = defineBlock<"cms:ogPreview", OgPreviewOptions>("cms:ogPreview", {
	behavior: "leaf",
	render: OgPreviewBlock,
});
