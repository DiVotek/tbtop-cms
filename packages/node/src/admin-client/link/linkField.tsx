import { useState } from "react";
import {
	Button,
	defineFieldClient,
	type FieldCellProps,
	type FieldFormProps,
	Input,
	inputCompactFontClass,
	Popover,
	PopoverContent,
	PopoverTrigger,
	Spinner,
	ToggleGroup,
	ToggleGroupItem,
} from "@tbtop/inertia-admin";
import { LabelRow } from "./LabelRow";
import { type PageOption, usePageOptions } from "./pageOptions";
import {
	emptyLink,
	LINK_TYPES,
	type LinkOptions,
	type LinkType,
	type LinkValue,
	parseLinkValue,
	pickLabel,
} from "./types";
import { type LinkErrorKey, linkError } from "./validate";

const DEFAULT_LABELS: Record<LinkType, string> = {
	page: "Page",
	external: "URL",
	email: "Email",
	phone: "Phone",
};

const DEFAULT_ERRORS: Record<LinkErrorKey, string> = {
	page: "Pick a page.",
	external: "Enter an http(s) URL, or a viber:/tg:/whatsapp:/skype:/sms: link.",
	email: "Enter a valid email address.",
	phone: "Enter 6 to 20 digits, optionally starting with +.",
	anchor: "Use letters, numbers, dashes and underscores only — no #.",
	label: "Keep the link text under 255 characters.",
};

function allowedTypes(options: LinkOptions | undefined): LinkType[] {
	const declared = options?.types;
	return declared !== undefined && declared.length > 0 ? declared : [...LINK_TYPES];
}

export function LinkForm({ id, value, onChange, onBlur, disabled, invalid, describedBy, options: raw }: FieldFormProps<LinkValue>) {
	const options = raw as LinkOptions | undefined;
	const types = allowedTypes(options);
	const link = parseLinkValue(value) ?? emptyLink(types[0] ?? "page");
	const error = linkError(link);
	const errorText = error === null ? null : (options?.errors?.[error] ?? DEFAULT_ERRORS[error]);
	const locales = options?.locales ?? [];
	// Lifted out of PageTarget: the label row needs the resolved page's names
	// for its per-locale placeholders.
	const remote = usePageOptions(options?.searchEndpoint, link.type === "page" ? link.page : null);

	// A type switch discards the target: an email address is not a page id,
	// and carrying it over would re-submit a stale one. The label is the
	// editor's text for any type, so it stays.
	const switchType = (next: string) => {
		if (next !== "" && next !== link.type) {
			onChange({ ...emptyLink(next as LinkType), label: link.label });
		}
	};

	return (
		<div className="flex flex-col gap-2">
			<ToggleGroup type="single" value={link.type} onValueChange={switchType} disabled={disabled}>
				{types.map((type) => (
					<ToggleGroupItem key={type} value={type} aria-label={options?.labels?.[type] ?? DEFAULT_LABELS[type]}>
						{options?.labels?.[type] ?? DEFAULT_LABELS[type]}
					</ToggleGroupItem>
				))}
			</ToggleGroup>

			{link.type === "page" ? (
				<PageTarget
					id={id}
					link={link}
					onChange={onChange}
					disabled={disabled}
					invalid={invalid || error !== null}
					describedBy={describedBy}
					options={options}
					remote={remote}
				/>
			) : (
				<Input
					id={id}
					type="text"
					value={link.value ?? ""}
					placeholder={options?.placeholders?.[link.type]}
					onChange={(e) => onChange({ ...link, value: e.target.value === "" ? null : e.target.value })}
					onBlur={onBlur}
					disabled={disabled}
					aria-invalid={invalid || error !== null}
					aria-describedby={describedBy}
				/>
			)}

			{locales.length === 0 ? null : (
				<LabelRow
					link={link}
					locales={locales}
					onChange={onChange}
					onBlur={onBlur}
					disabled={disabled}
					selected={remote.selected}
					label={options?.textLabel ?? "Text"}
				/>
			)}

			{errorText === null ? null : (
				<p className="text-xs text-destructive" role="alert">
					{errorText}
				</p>
			)}
		</div>
	);
}

interface PageTargetProps {
	id?: string;
	link: LinkValue;
	onChange: (next: LinkValue) => void;
	disabled?: boolean;
	invalid?: boolean;
	describedBy?: string;
	options?: LinkOptions;
	remote: ReturnType<typeof usePageOptions>;
}

function PageTarget({ id, link, onChange, disabled, invalid, describedBy, options, remote }: PageTargetProps) {
	const [isOpen, setOpen] = useState(false);
	const [term, setTerm] = useState("");

	const open = (next: boolean) => {
		setOpen(next);
		if (next) {
			remote.search(term);
		}
	};

	const pick = (option: PageOption) => {
		remote.noteSeen(option);
		onChange({ ...link, page: option.value });
		setOpen(false);
	};

	return (
		<div className="flex items-start gap-2">
			<Popover open={isOpen} onOpenChange={open}>
				<PopoverTrigger asChild>
					<Button
						id={id}
						type="button"
						variant="outline"
						disabled={disabled}
						aria-invalid={invalid}
						aria-describedby={describedBy}
						className="min-w-0 flex-1 justify-start font-normal"
					>
						<span className="truncate">
							{remote.selected?.label ?? link.page ?? (options?.pagePlaceholder ?? "Search pages…")}
						</span>
					</Button>
				</PopoverTrigger>
				<PopoverContent className="w-72 p-1">
					<Input
						autoFocus
						type="text"
						value={term}
						placeholder={options?.pagePlaceholder}
						aria-label={options?.pageSearchLabel ?? "Search pages"}
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
						<p className="px-2 py-3 text-center text-sm text-muted-foreground">{options?.pageEmpty ?? "No pages found."}</p>
					) : (
						<ul className="mt-1 max-h-64 overflow-y-auto">
							{remote.options.map((option) => (
								<li key={option.value}>
									<button
										type="button"
										className="w-full truncate rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
										onClick={() => pick(option)}
									>
										{option.label}
									</button>
								</li>
							))}
						</ul>
					)}
				</PopoverContent>
			</Popover>

			<Input
				type="text"
				value={link.anchor ?? ""}
				aria-label={options?.anchorLabel ?? "Anchor"}
				placeholder={options?.anchorPlaceholder ?? "section-id"}
				onChange={(e) => onChange({ ...link, anchor: e.target.value === "" ? null : e.target.value })}
				disabled={disabled}
				className={`w-28 shrink-0 font-mono ${inputCompactFontClass}`}
			/>
		</div>
	);
}

export function LinkCell({ value, options: raw }: FieldCellProps<LinkValue>) {
	const options = raw as LinkOptions | undefined;
	const link = parseLinkValue(value);
	if (link === null) {
		return <span className="text-muted-foreground">—</span>;
	}
	return <span className="truncate">{summary(link, options)}</span>;
}

function summary(link: LinkValue, options?: LinkOptions): string {
	const authored = pickLabel(link, options?.defaultLocale);
	if (authored !== null) {
		return authored;
	}
	if (link.type === "page") {
		const label = options?.labels?.page ?? DEFAULT_LABELS.page;
		const target = link.page === null ? "—" : `${label} #${link.page}`;
		return `→ ${target}${link.anchor === null ? "" : `#${link.anchor}`}`;
	}
	const value = link.value ?? "—";
	if (link.type === "email") {
		return `mailto:${value}`;
	}
	if (link.type === "phone") {
		return `tel:${value}`;
	}
	return value;
}

export const linkFieldClient = defineFieldClient<"link", LinkValue>("link", {
	form: LinkForm,
	cell: LinkCell,
});
