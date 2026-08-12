import { defineBlock, type RenderProps } from "@tbtop/inertia-admin";
import { type CSSProperties, useCallback, useEffect, useState } from "react";
import { layOutText, type TextLayout } from "./measureText";
import { useContentLocale } from "./useContentLocale";

type Device = "desktop" | "mobile";

interface LocaleCard {
	url: string;
	title: string;
	description: string;
}

interface SerpPreviewOptions {
	/** One resolved card per content locale — the client picks by active tab. */
	byLocale: Record<string, LocaleCard>;
	defaultLocale: string;
	emptyTitle: string;
	emptyDescription: string;
	labels: {
		desktop: string;
		mobile: string;
		titleWidth: string;
		descriptionLength: string;
		truncated: string;
		sectionTitle: string;
		summary: string;
		expand: string;
		collapse: string;
	};
}

/**
 * Google cuts the title by rendered width, not character count — the limits
 * are the widths of its own result column, so they differ per device. The
 * description is cut by characters instead, which is why only the title gets
 * a pixel meter. Both numbers come from Yoast's published measurements.
 *
 * Mobile's larger budget is two lines' worth, not one wide line, so the card
 * lays it out over `lines` and each line gets limit/lines of room. Keeping the
 * card's own column tied to the same numbers is what stops the meter and the
 * card from disagreeing — a title the meter passes has to fit the box drawn
 * above it, and one it rejects has to visibly overflow.
 */
const TITLE_WIDTH_LIMIT: Record<Device, number> = { desktop: 600, mobile: 920 };
const TITLE_LINES: Record<Device, number> = { desktop: 1, mobile: 2 };
const DESCRIPTION_LIMIT: Record<Device, number> = { desktop: 155, mobile: 120 };

/**
 * The typography a result title is rendered in, which is what the width limits
 * above were measured against — not the admin's own font stack.
 *
 * The card and the meter both read from here, as a CSS declaration and as a
 * canvas font string, so the number under the card always describes the text
 * above it. Styling the title with the admin's `text-lg font-medium` instead
 * would measure one font against another font's limit.
 */
const TITLE_TYPE: Record<Device, { size: number; weight: number }> = {
	desktop: { size: 20, weight: 400 },
	mobile: { size: 18, weight: 400 },
};

const TITLE_FAMILY = 'Arial, "Helvetica Neue", Helvetica, sans-serif';

function titleFont(device: Device): string {
	const { size, weight } = TITLE_TYPE[device];
	return `${weight} ${size}px ${TITLE_FAMILY}`;
}

function titleStyle(device: Device): CSSProperties {
	const { size, weight } = TITLE_TYPE[device];
	return { fontSize: `${size}px`, fontWeight: weight, fontFamily: TITLE_FAMILY };
}

/** One line's worth of the device's width budget. */
function columnWidth(device: Device): number {
	return Math.round(TITLE_WIDTH_LIMIT[device] / TITLE_LINES[device]);
}

/** The card's own px-4 on both sides, so its box wraps the column exactly. */
const CARD_PADDING = 32;

const OPEN_KEY = "tbtop-cms.serpPreview.open";

/**
 * The card starts collapsed so the fields it describes are the first thing on
 * the tab — the meters stay in the header either way, so an overflowing title
 * is visible without opening anything.
 *
 * The choice is remembered because it is a working preference, not a per-page
 * one: an editor who wants the snippet in view wants it on every page, and
 * re-opening it on each navigation is the kind of small tax that makes a panel
 * feel hostile. Storage failures (private mode, disabled storage) fall back to
 * the default rather than breaking the block.
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

export function SerpPreviewBlock({ options }: RenderProps<SerpPreviewOptions>) {
	const [device, setDevice] = useState<Device>("desktop");
	const [open, toggle] = useRememberedOpen();

	// Follows the form's locale tabs. A single-locale form renders no tab strip,
	// and a locale the server did not resolve (added mid-session) falls back to
	// the default rather than blanking the card.
	const activeLocale = useContentLocale();
	const card =
		(activeLocale === null ? undefined : options.byLocale[activeLocale]) ??
		options.byLocale[options.defaultLocale];

	// The placeholders are what an empty field looks like, not what it sends:
	// the meters read the real values, so a page with no title reports zero
	// rather than the width of "No title yet".
	const title = card?.title ?? "";
	const description = card?.description ?? "";

	const column = columnWidth(device);
	const layout = useTitleLayout(title, titleFont(device), column);
	const widthLimit = TITLE_WIDTH_LIMIT[device];
	const descriptionLimit = DESCRIPTION_LIMIT[device];

	// The title overflows when its wrapped form needs more lines than the
	// device shows — the same thing the card's clamp does to it. Layout is
	// unknown until the measuring effect has run once; treating that first
	// paint as overflow would flash a red meter on every mount.
	const titleOver = layout !== null && layout.lines > TITLE_LINES[device];
	const descriptionOver = countCharacters(description) > descriptionLimit;

	// Reported as the budget the wrapped title consumes: every line but the
	// last one fills the column, the last one takes what it needs. An
	// overflowing title reports past the limit, which is what the bar shows.
	const titleWidth = layout === null ? null : column * (layout.lines - 1) + layout.width;

	const titleText = titleWidth === null ? "—" : Math.round(titleWidth);
	const descriptionLength = countCharacters(description);
	const anyOver = titleOver || descriptionOver;

	return (
		<div className="flex flex-col gap-3 rounded-lg border bg-card px-4 py-3">
			{/* The header carries the verdict on its own: collapsed is the
			    default, so the numbers have to be readable without opening the
			    card, or an overflowing title would hide behind a chevron. */}
			<button
				type="button"
				onClick={toggle}
				aria-expanded={open}
				className="flex w-full items-center justify-between gap-3 text-left"
			>
				<span className="text-sm font-medium text-foreground">
					{options.labels.sectionTitle}
				</span>
				<span className="flex items-center gap-2">
					<span
						className={
							anyOver
								? "text-xs tabular-nums text-destructive"
								: "text-xs tabular-nums text-muted-foreground"
						}
					>
						{fill(options.labels.summary, {
							width: titleText,
							widthLimit,
							length: descriptionLength,
							lengthLimit: descriptionLimit,
						})}
					</span>
					<span aria-hidden className="text-xs text-muted-foreground">
						{open ? "▾" : "▸"}
					</span>
					<span className="sr-only">
						{open ? options.labels.collapse : options.labels.expand}
					</span>
				</span>
			</button>

			{open ? (
		<div className="flex flex-col gap-3">
			<DeviceSwitch device={device} onChange={setDevice} labels={options.labels} />

			<div
				className="w-fit rounded-lg border bg-background px-4 py-3.5"
				style={{ maxWidth: `${column + CARD_PADDING}px` }}
			>
				<div className="truncate text-[13px] leading-tight text-muted-foreground">
					{card?.url ?? ""}
				</div>
				<div
					className="mt-1 overflow-hidden text-ellipsis leading-snug text-foreground"
					style={{
						...titleStyle(device),
						// The box the meter's verdict describes: as wide as one
						// line of the budget, as tall as the lines it is spread
						// over. A title that overflows here is the same title the
						// meter reports as over the limit.
						width: `${column}px`,
						display: "-webkit-box",
						WebkitBoxOrient: "vertical",
						WebkitLineClamp: TITLE_LINES[device],
						// Matches how layOutText() breaks a word wider than the
						// column; without it the card would overflow where the
						// measurement wrapped.
						overflowWrap: "anywhere",
					}}
				>
					{title || options.emptyTitle}
				</div>
				<div className="mt-1.5 line-clamp-2 text-sm leading-normal text-muted-foreground">
					{description || options.emptyDescription}
				</div>
			</div>

				<Meter
					label={fill(options.labels.titleWidth, {
						// Only the width has a limit — a title is cut by how wide it
						// renders, never by its character count. The count rides along
						// because it is what the editor is actually typing against.
						width: titleText,
						limit: widthLimit,
						length: countCharacters(title),
					})}
					value={titleWidth ?? 0}
					limit={widthLimit}
					over={titleOver}
					note={titleOver ? options.labels.truncated : null}
				/>
				<Meter
					label={fill(options.labels.descriptionLength, {
						length: descriptionLength,
						limit: descriptionLimit,
					})}
					value={descriptionLength}
					limit={descriptionLimit}
					over={descriptionOver}
					note={descriptionOver ? options.labels.truncated : null}
				/>
				</div>
			) : null}
		</div>
	);
}

const segmenter =
	typeof Intl !== "undefined" && "Segmenter" in Intl ? new Intl.Segmenter() : null;

/**
 * Counts what an editor sees as one character. String.length counts UTF-16
 * code units, so an emoji reads as two and a flag as four; a grapheme count
 * matches the glyphs on screen, which is what the number claims to describe.
 *
 * The code-point fallback is for environments with no Intl at all — SSR and
 * test DOMs — rather than for real browsers: every engine in the build's
 * baseline ships Segmenter. It still splits flags and ZWJ sequences, so a
 * count taken there can read high; that is preferable to shipping a grapheme
 * segmentation table for a path the admin's own browsers never take.
 */
function countCharacters(text: string): number {
	if (segmenter) {
		let count = 0;
		for (const _ of segmenter.segment(text)) {
			count++;
		}
		return count;
	}

	return Array.from(text).length;
}

/**
 * Substitutes Laravel-style `:name` placeholders. The translations keep them
 * unresolved on purpose — every number here depends on the selected device or
 * on text measured in the browser, neither of which the server knows.
 */
function fill(template: string, values: Record<string, string | number>): string {
	// Longest key first: `:width` is a prefix of `:widthLimit`, so substituting
	// in declaration order would consume the shorter name and leave "Limit"
	// stranded in the output.
	return Object.entries(values)
		.sort(([a], [b]) => b.length - a.length)
		.reduce((text, [key, value]) => text.replaceAll(`:${key}`, String(value)), template);
}

function DeviceSwitch({
	device,
	onChange,
	labels,
}: {
	device: Device;
	onChange: (next: Device) => void;
	labels: SerpPreviewOptions["labels"];
}) {
	return (
		<div className="inline-flex w-fit gap-1 rounded-md bg-muted p-1">
			{(["desktop", "mobile"] as const).map((value) => (
				<button
					key={value}
					type="button"
					aria-pressed={device === value}
					onClick={() => onChange(value)}
					className={
						device === value
							? "rounded px-3 py-1 text-sm font-medium bg-background text-foreground shadow-sm"
							: "rounded px-3 py-1 text-sm font-medium text-muted-foreground"
					}
				>
					{labels[value]}
				</button>
			))}
		</div>
	);
}

/**
 * Fill tracks the value against its limit; anything past the limit pins the
 * bar full and turns it red, so overflow reads as "cut off" rather than as a
 * bar that silently keeps growing.
 */
function Meter({
	label,
	value,
	limit,
	over,
	note,
}: {
	label: string;
	value: number;
	limit: number;
	over: boolean;
	note: string | null;
}) {
	const percent = Math.min(100, Math.round((value / limit) * 100));

	return (
		<div className="flex flex-col gap-1">
			<div className="flex items-baseline justify-between gap-2">
				<span className="text-xs text-muted-foreground">{label}</span>
				{note ? <span className="text-xs text-destructive">{note}</span> : null}
			</div>
			<div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
				<div
					className={over ? "h-full bg-destructive" : "h-full bg-primary"}
					style={{ width: `${percent}%` }}
				/>
			</div>
		</div>
	);
}

/** Re-lays out on any text, font or column change; null until the first pass lands. */
function useTitleLayout(text: string, font: string, column: number): TextLayout | null {
	const [layout, setLayout] = useState<TextLayout | null>(null);

	useEffect(() => {
		setLayout(layOutText(text, font, column));
	}, [text, font, column]);

	return layout;
}

export const serpPreviewBlock = defineBlock<"cms:serpPreview", SerpPreviewOptions>(
	"cms:serpPreview",
	{ behavior: "leaf", render: SerpPreviewBlock },
);
