import { useState } from "react";
import {
	Badge,
	Button,
	defineFieldClient,
	type FieldCellProps,
	type FieldFormProps,
	Input,
	Popover,
	PopoverContent,
	PopoverTrigger,
	Spinner,
	ToggleGroup,
	ToggleGroupItem,
} from "@tbtop/inertia-admin";
import { type EntryOption, useEntryOptions } from "./entryOptions";
import {
	allowedModes,
	type CollectionOptions,
	type CollectionValue,
	type PickMode,
	parseCollectionValue,
	valueForMode,
} from "./types";

const DEFAULT_LABELS: Record<PickMode, string> = {
	all: "All",
	latest: "Latest",
	related: "Related to the page",
	manual: "Pick by hand",
};

function modeLabel(mode: PickMode, options: CollectionOptions | undefined): string {
	return options?.labels?.[mode] ?? DEFAULT_LABELS[mode];
}

/**
 * The `collection` block field: the editor picks how the block gets its
 * entries — all of them, the latest N, those related to the page, or a
 * hand-picked, ordered list. Stores `{mode, limit?, ids?}`.
 */
export function CollectionForm({ id, value, onChange, disabled, invalid, describedBy, options: raw }: FieldFormProps<CollectionValue>) {
	const options = raw as CollectionOptions | undefined;
	const modes = allowedModes(options);
	const current = parseCollectionValue(value, options);
	const cap = typeof options?.cap === "number" ? options.cap : null;

	const switchMode = (next: string) => {
		if (next !== "" && next !== current.mode) {
			onChange(valueForMode(next as PickMode, options));
		}
	};

	return (
		<div className="flex flex-col gap-2">
			{modes.length < 2 ? null : (
				<ToggleGroup type="single" value={current.mode} onValueChange={switchMode} disabled={disabled}>
					{modes.map((mode) => (
						<ToggleGroupItem key={mode} value={mode} aria-label={modeLabel(mode, options)}>
							{modeLabel(mode, options)}
						</ToggleGroupItem>
					))}
				</ToggleGroup>
			)}

			{current.mode === "latest" && cap !== 1 ? (
				<label className="flex items-center gap-2 text-sm">
					<span>{options?.limitLabel ?? "How many"}</span>
					<LimitInput
						id={id}
						limit={current.limit ?? options?.defaultLimit ?? 3}
						cap={cap}
						onChange={(limit) => onChange({ mode: "latest", limit })}
						disabled={disabled}
						invalid={invalid}
						describedBy={describedBy}
					/>
				</label>
			) : null}

			{current.mode === "manual" ? (
				<ManualPicks
					id={id}
					ids={current.ids ?? []}
					cap={cap}
					onChange={(ids) => onChange({ mode: "manual", ids })}
					disabled={disabled}
					invalid={invalid}
					describedBy={describedBy}
					options={options}
				/>
			) : null}
		</div>
	);
}

/** A typed count, clamped to 1…cap; null while the text is no number yet (cleared to retype), so nothing is saved. */
export function limitFrom(text: string, cap: number | null): number | null {
	const typed = Number.parseInt(text, 10);
	return Number.isNaN(typed) ? null : Math.max(1, cap === null ? typed : Math.min(cap, typed));
}

interface LimitInputProps {
	id?: string;
	limit: number;
	cap: number | null;
	onChange: (limit: number) => void;
	disabled?: boolean;
	invalid?: boolean;
	describedBy?: string;
}

/** The latest-N count: the typed text stays as typed (so it can be cleared), a number is clamped and saved, blur shows the saved one. */
function LimitInput({ id, limit, cap, onChange, disabled, invalid, describedBy }: LimitInputProps) {
	const [draft, setDraft] = useState<string | null>(null);

	return (
		<Input
			id={id}
			type="number"
			min={1}
			max={cap ?? undefined}
			value={draft ?? String(limit)}
			onChange={(e) => {
				setDraft(e.target.value);
				const next = limitFrom(e.target.value, cap);
				if (next !== null) {
					onChange(next);
				}
			}}
			onBlur={() => setDraft(null)}
			disabled={disabled}
			aria-invalid={invalid}
			aria-describedby={describedBy}
			className="w-24"
		/>
	);
}

interface ManualPicksProps {
	id?: string;
	ids: string[];
	cap: number | null;
	onChange: (ids: string[]) => void;
	disabled?: boolean;
	invalid?: boolean;
	describedBy?: string;
	options?: CollectionOptions;
}

function ManualPicks({ id, ids, cap, onChange, disabled, invalid, describedBy, options }: ManualPicksProps) {
	const remote = useEntryOptions(options?.searchEndpoint, ids);
	const [isOpen, setOpen] = useState(false);
	const [term, setTerm] = useState("");
	const isFull = cap !== null && ids.length >= cap;

	const open = (next: boolean) => {
		setOpen(next);
		if (next) {
			remote.search(term);
		}
	};

	const pick = (option: EntryOption) => {
		// One pick replaces the other when only one fits, as a single select does.
		onChange(cap === 1 ? [option.value] : [...ids.filter((v) => v !== option.value), option.value]);
		setOpen(false);
	};

	const move = (index: number, by: number) => {
		const next = [...ids];
		const [item] = next.splice(index, 1);
		if (item !== undefined) {
			next.splice(index + by, 0, item);
			onChange(next);
		}
	};

	return (
		<div className="flex flex-col gap-1">
			{ids.length === 0 ? null : (
				<ul className="flex flex-col gap-1" aria-describedby={describedBy}>
					{ids.map((value, index) => {
						const option = remote.known[value];
						return (
							<li key={value} className="flex items-center gap-2 rounded border px-2 py-1 text-sm">
								<span className="min-w-0 flex-1 truncate">{option?.label ?? `#${value}`}</span>
								{option?.draft ? <Badge variant="secondary">draft</Badge> : null}
								<Button type="button" variant="ghost" size="sm" disabled={disabled || index === 0} onClick={() => move(index, -1)} aria-label="Up">
									↑
								</Button>
								<Button
									type="button"
									variant="ghost"
									size="sm"
									disabled={disabled || index === ids.length - 1}
									onClick={() => move(index, 1)}
									aria-label="Down"
								>
									↓
								</Button>
								<Button
									type="button"
									variant="ghost"
									size="sm"
									disabled={disabled}
									onClick={() => onChange(ids.filter((v) => v !== value))}
									aria-label={options?.removeLabel ?? "Remove"}
								>
									×
								</Button>
							</li>
						);
					})}
				</ul>
			)}

			{isFull && cap !== 1 ? null : (
				<Popover open={isOpen} onOpenChange={open}>
					<PopoverTrigger asChild>
						<Button id={id} type="button" variant="outline" disabled={disabled} aria-invalid={invalid} className="justify-start font-normal">
							{options?.addLabel ?? "Add"}
							{options?.collectionLabel === undefined ? "" : ` · ${options.collectionLabel}`}
						</Button>
					</PopoverTrigger>
					<PopoverContent className="w-72 p-1">
						<Input
							autoFocus
							type="text"
							value={term}
							placeholder={options?.searchPlaceholder ?? "Search…"}
							aria-label={options?.searchPlaceholder ?? "Search"}
							onChange={(e) => {
								setTerm(e.target.value);
								remote.search(e.target.value);
							}}
						/>
						{remote.isLoading ? (
							<div className="flex justify-center py-3">
								<Spinner />
							</div>
						) : remote.options.length === 0 ? (
							<p className="px-2 py-3 text-center text-sm text-muted-foreground">{options?.emptyText ?? "Nothing found."}</p>
						) : (
							<ul className="mt-1 max-h-64 overflow-y-auto">
								{remote.options.map((option) => (
									<li key={option.value}>
										<button
											type="button"
											className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent disabled:opacity-50"
											disabled={ids.includes(option.value) && cap !== 1}
											onClick={() => pick(option)}
										>
											<span className="min-w-0 flex-1 truncate">{option.label}</span>
											{option.draft ? <Badge variant="secondary">draft</Badge> : null}
										</button>
									</li>
								))}
							</ul>
						)}
					</PopoverContent>
				</Popover>
			)}
		</div>
	);
}

export function CollectionCell({ value, options: raw }: FieldCellProps<CollectionValue>) {
	const options = raw as CollectionOptions | undefined;
	const current = parseCollectionValue(value, options);
	const label = modeLabel(current.mode, options);
	if (current.mode === "latest") {
		return <span>{`${label}: ${current.limit ?? options?.defaultLimit ?? 3}`}</span>;
	}
	if (current.mode === "manual") {
		return <span>{`${label}: ${(current.ids ?? []).length}`}</span>;
	}
	return <span>{label}</span>;
}

export const collectionFieldClient = defineFieldClient<"collection", CollectionValue>("collection", {
	form: CollectionForm,
	cell: CollectionCell,
});
