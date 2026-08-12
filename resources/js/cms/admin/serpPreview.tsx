import { defineBlock, type RenderProps } from "@tbtop/inertia-admin";
import { type CSSProperties, useEffect, useState } from "react";
import { measureTextWidth } from "./measureText";

type Device = "desktop" | "mobile";

interface SerpPreviewOptions {
	url: string;
	title: string;
	description: string;
	emptyTitle: string;
	emptyDescription: string;
	labels: {
		desktop: string;
		mobile: string;
		titleWidth: string;
		descriptionLength: string;
		truncated: string;
	};
}

/**
 * Google cuts the title by rendered width, not character count — the limits
 * are the widths of its own result column, so they differ per device. The
 * description is cut by characters instead, which is why only the title gets
 * a pixel meter. Both numbers come from Yoast's published measurements.
 */
const TITLE_WIDTH_LIMIT: Record<Device, number> = { desktop: 600, mobile: 920 };
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

export function SerpPreviewBlock({ options }: RenderProps<SerpPreviewOptions>) {
	const [device, setDevice] = useState<Device>("desktop");

	const title = options.title || options.emptyTitle;
	const description = options.description || options.emptyDescription;

	const titleWidth = useMeasuredWidth(title, titleFont(device));
	const widthLimit = TITLE_WIDTH_LIMIT[device];
	const descriptionLimit = DESCRIPTION_LIMIT[device];

	// Width is unknown until the measuring effect has run once; treating that
	// first paint as "over the limit" would flash a red meter on every mount.
	const titleOver = titleWidth !== null && titleWidth > widthLimit;
	const descriptionOver = description.length > descriptionLimit;

	return (
		<div className="flex flex-col gap-3">
			<DeviceSwitch device={device} onChange={setDevice} labels={options.labels} />

			<div
				className={
					device === "mobile"
						? "max-w-[420px] rounded-lg border bg-card px-4 py-3.5"
						: "rounded-lg border bg-card px-4 py-3.5"
				}
			>
				<div className="truncate text-[13px] leading-tight text-muted-foreground">
					{options.url}
				</div>
				<div
					className={
						device === "mobile"
							? "mt-1 line-clamp-2 leading-snug text-foreground"
							: "mt-1 truncate leading-snug text-foreground"
					}
					style={titleStyle(device)}
				>
					{title}
				</div>
				<div className="mt-1.5 line-clamp-2 text-sm leading-normal text-muted-foreground">
					{description}
				</div>
			</div>

			<Meter
				label={fill(options.labels.titleWidth, {
					// Only the width has a limit — a title is cut by how wide it
					// renders, never by its character count. The count rides along
					// because it is what the editor is actually typing against.
					width: titleWidth === null ? "—" : Math.round(titleWidth),
					limit: widthLimit,
					length: title.length,
				})}
				value={titleWidth ?? 0}
				limit={widthLimit}
				over={titleOver}
				note={titleOver ? options.labels.truncated : null}
			/>
			<Meter
				label={fill(options.labels.descriptionLength, {
					length: description.length,
					limit: descriptionLimit,
				})}
				value={description.length}
				limit={descriptionLimit}
				over={descriptionOver}
				note={descriptionOver ? options.labels.truncated : null}
			/>
		</div>
	);
}

/**
 * Substitutes Laravel-style `:name` placeholders. The translations keep them
 * unresolved on purpose — every number here depends on the selected device or
 * on text measured in the browser, neither of which the server knows.
 */
function fill(template: string, values: Record<string, string | number>): string {
	return Object.entries(values).reduce(
		(text, [key, value]) => text.replaceAll(`:${key}`, String(value)),
		template,
	);
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

/** Remeasures whenever the text or the font changes; null until the first measure lands. */
function useMeasuredWidth(text: string, font: string): number | null {
	const [width, setWidth] = useState<number | null>(null);

	useEffect(() => {
		setWidth(measureTextWidth(text, font));
	}, [text, font]);

	return width;
}

export const serpPreviewBlock = defineBlock<"cms:serpPreview", SerpPreviewOptions>(
	"cms:serpPreview",
	{ behavior: "leaf", render: SerpPreviewBlock },
);
