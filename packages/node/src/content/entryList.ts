import type { Page } from "./page";

/** One resolved entry: its id and its fields as the site gets them. */
export type ResolvedEntry = { id: number } & Record<string, unknown>;

/**
 * A collection's published entries in one locale, in the editor's order
 * (EntryList.php). Immutable: every filter returns a new list and runs in
 * memory over the cached collection.
 */
export class EntryList implements Iterable<ResolvedEntry> {
	constructor(
		private readonly entries: readonly ResolvedEntry[],
		/** The `pages` field `relatedTo` reads; null when the collection has none. */
		private readonly relatedField: string | null = null,
	) {}

	all(): ResolvedEntry[] {
		return [...this.entries];
	}

	first(): ResolvedEntry | null {
		return this.entries[0] ?? null;
	}

	get count(): number {
		return this.entries.length;
	}

	isEmpty(): boolean {
		return this.entries.length === 0;
	}

	take(count: number): EntryList {
		return this.with(this.entries.slice(0, Math.max(0, count)));
	}

	/** Newest first (highest id); `count` keeps that many. */
	latest(count?: number): EntryList {
		const sorted = [...this.entries].sort((a, b) => b.id - a.id);
		return this.with(count === undefined ? sorted : sorted.slice(0, Math.max(0, count)));
	}

	/** The entries whose related `pages` field picks the page; empty without a page or a related field. */
	relatedTo(page: Page | number | null): EntryList {
		const id = page === null ? null : typeof page === "number" ? page : page.id;
		const field = this.relatedField;
		if (id === null || field === null) {
			return this.with([]);
		}
		return this.with(this.entries.filter((entry) => Array.isArray(entry[field]) && (entry[field] as unknown[]).some((ref) => Number((ref as { id?: unknown } | null)?.id) === id)));
	}

	/** The entries with these ids, in the order given; unknown ids are skipped. */
	only(ids: Iterable<number | string>): EntryList {
		const byId = new Map(this.entries.map((entry) => [entry.id, entry]));
		const seen = new Set<number>();
		const out: ResolvedEntry[] = [];
		for (const raw of ids) {
			const id = Number(raw);
			const entry = byId.get(id);
			if (entry !== undefined && !seen.has(id)) {
				seen.add(id);
				out.push(entry);
			}
		}
		return this.with(out);
	}

	/** The entries whose resolved `field` equals `value` (a list field: contains it). */
	where(field: string, value: unknown): EntryList {
		return this.with(this.entries.filter((entry) => (Array.isArray(entry[field]) ? (entry[field] as unknown[]).includes(value) : entry[field] === value)));
	}

	[Symbol.iterator](): Iterator<ResolvedEntry> {
		return this.entries[Symbol.iterator]();
	}

	toJSON(): ResolvedEntry[] {
		return this.all();
	}

	private with(entries: ResolvedEntry[]): EntryList {
		return new EntryList(entries, this.relatedField);
	}
}
