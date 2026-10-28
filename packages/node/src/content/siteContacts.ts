import type { Site } from "../site";
import { OFFICES, SOCIALS } from "./builtInCollections";
import type { ResolvedEntry } from "./entryList";

/** One social link as blocks and the identity read it. */
export type SocialLink = { label: string; name: string | null; href: string };

const text = (value: unknown): string | null => (typeof value === "string" && value.trim() !== "" ? value.trim() : null);

/** One string column of repeater rows, blanks skipped (SiteContacts::column). */
export function column(rows: unknown, field: string): string[] {
	const out: string[] = [];
	for (const row of Array.isArray(rows) ? rows : []) {
		const value = typeof row === "object" && row !== null ? text((row as Record<string, unknown>)[field]) : null;
		if (value !== null) {
			out.push(value);
		}
	}
	return out;
}

/**
 * The site's contact details for blocks and the SEO identity, from the
 * built-in collections (SiteContacts.php): phones and emails are the main
 * office's (the first published Offices entry), the socials every
 * published Socials entry. Each read is the cached collection.
 */
export class SiteContacts {
	constructor(private readonly site: Site) {}

	/** The published offices resolved for the locale, the main one first. */
	async offices(locale?: string): Promise<ResolvedEntry[]> {
		return (await this.site.collection(OFFICES, locale)).all();
	}

	async phones(locale?: string): Promise<string[]> {
		return column((await this.offices(locale))[0]?.phones, "number");
	}

	async emails(locale?: string): Promise<string[]> {
		return column((await this.offices(locale))[0]?.emails, "address");
	}

	/** Entries missing a label or a link are skipped. */
	async socials(locale?: string): Promise<SocialLink[]> {
		const out: SocialLink[] = [];
		for (const entry of await this.site.collection(SOCIALS, locale)) {
			const label = text(entry.label);
			const href = text(entry.href);
			if (label !== null && href !== null) {
				out.push({ label, name: text(entry.name), href });
			}
		}
		return out;
	}

	/**
	 * The `cms.settings` keys the lists had before the collections (ShareCmsProps::contacts),
	 * in their old row shapes, so templates that read them keep working.
	 */
	async legacySettings(locale: string): Promise<Record<string, unknown>> {
		return {
			"site.contacts.phones": (await this.phones(locale)).map((number) => ({ number })),
			"site.contacts.emails": (await this.emails(locale)).map((address) => ({ address })),
			"site.contacts.socials": await this.socials(locale),
		};
	}
}
