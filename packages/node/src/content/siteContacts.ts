import type { Site } from "../site";
import { EMAILS, PHONES, SOCIALS } from "./builtInCollections";
import { safeHref } from "./safeHref";

/** One social link as blocks and the identity read it. */
export type SocialLink = { label: string; name: string | null; href: string };

const text = (value: unknown): string | null => (typeof value === "string" && value.trim() !== "" ? value.trim() : null);

/**
 * The site's contact details for blocks and the SEO identity, from the
 * built-in collections (SiteContacts.php): every published Phones, Emails
 * and Socials entry, in the editor's order. Each read is the cached
 * collection.
 */
export class SiteContacts {
	constructor(private readonly site: Site) {}

	async phones(locale?: string): Promise<string[]> {
		return this.column(PHONES, "number", locale);
	}

	async emails(locale?: string): Promise<string[]> {
		return this.column(EMAILS, "address", locale);
	}

	/** Entries missing a label or a link, or with a script link stored past the form, are skipped. */
	async socials(locale?: string): Promise<SocialLink[]> {
		const out: SocialLink[] = [];
		for (const entry of await this.site.collection(SOCIALS, locale)) {
			const label = text(entry.label);
			const href = safeHref(text(entry.href));
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

	/** One field of a collection's published entries, blanks skipped. */
	private async column(collection: string, field: string, locale?: string): Promise<string[]> {
		const out: string[] = [];
		for (const entry of await this.site.collection(collection, locale)) {
			const value = text(entry[field]);
			if (value !== null) {
				out.push(value);
			}
		}
		return out;
	}
}
