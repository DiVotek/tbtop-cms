import { Input, inputCompactFontClass } from "@tbtop/inertia-admin";
import type { PageOption } from "./pageOptions";
import { type LinkValue, withLabel } from "./types";

interface LabelRowProps {
	link: LinkValue;
	locales: string[];
	onChange: (next: LinkValue) => void;
	onBlur?: () => void;
	disabled?: boolean;
	/** Resolved target, so a page link can show the page's own name per locale. */
	selected: PageOption | null;
	label: string;
}

/**
 * One label input per content locale. Locales come from the node options
 * because useActiveLocale/useContentLocaleConfig are not exported from
 * @tbtop/inertia-admin; this can collapse to the active locale once they are.
 */
export function LabelRow({ link, locales, onChange, onBlur, disabled, selected, label }: LabelRowProps) {
	return (
		<fieldset className="flex flex-col gap-1">
			<legend className="text-xs text-muted-foreground">{label}</legend>
			{locales.map((locale) => (
				<div key={locale} className="flex items-center gap-2">
					<span className={`w-8 shrink-0 uppercase text-muted-foreground ${inputCompactFontClass}`}>{locale}</span>
					<Input
						type="text"
						value={link.label?.[locale] ?? ""}
						aria-label={`${label} (${locale})`}
						placeholder={placeholder(link, selected, locale)}
						onChange={(e) => onChange(withLabel(link, locale, e.target.value))}
						onBlur={onBlur}
						disabled={disabled}
					/>
				</div>
			))}
		</fieldset>
	);
}

/** What the server would fall back to, so the author sees the text they'd get. */
function placeholder(link: LinkValue, selected: PageOption | null, locale: string): string | undefined {
	if (link.type === "page") {
		return selected?.names[locale] ?? selected?.label;
	}
	return link.value ?? undefined;
}
