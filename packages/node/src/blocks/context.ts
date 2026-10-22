import type { ResolvedLink } from "../content/link";
import type { Page, TranslatableField } from "../content/page";

/** What a block's or page type's resolve() reads besides its own input (ResolveContext.php). */
export type ResolveContext = {
	locale: string;
	fallbackLocale: string;
	/** The page being rendered; null for header/footer layout blocks. */
	page: Page | null;
	/** The page type's resolved custom fields. */
	fields: Record<string, unknown>;
	mediaUrl(ref: string): string | null;
	richtextHtml(value: unknown): string;
	link(value: unknown): Promise<ResolvedLink | null>;
	/** A translatable page column as text, locale then fallback. */
	pageText(field: TranslatableField): string | null;
	/** A package string (`forms.contact.heading`) in this locale. */
	t(key: string): string;
};
