import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage, S } from "@tbtop/admin";
import { z } from "zod";
import { BUILT_IN_COLLECTIONS, cmsBlockImpls, cmsBlocks, type CmsConfig, type CollectionDef, collectionField, Collections, defineBlocks, EntryConflictError, implementBlocks, memoryCache, pagesField } from "../src";
import { block, collection, collectionDefs, defineSite, f } from "../src/define";
import { get, makeCms } from "./support";

/** Reviews tied to services by a `pages` field: the "related" mode reads it. */
const reviews: CollectionDef = {
	key: "review",
	label: "Reviews",
	singularLabel: "review",
	form: (s) => [s.text("author").required(), s.textarea("text").translatable(), s.number("rating"), pagesField("services")],
};

const team: CollectionDef = { key: "team", label: "Team", form: (s) => [s.text("name").required(), s.text("role")] };

const catalog = defineBlocks({
	...cmsBlocks,
	Reviews: { props: z.object({ reviews: z.array(z.looseObject({ id: z.number() })), featured: z.looseObject({ id: z.number() }).nullable() }) },
});

const blocks = () =>
	implementBlocks(catalog, {
		...cmsBlockImpls,
		Reviews: { form: () => [collectionField("reviews").of("review"), collectionField("featured").of("review").single().modes(["manual", "latest"])] },
	});

const fixture = {
	languages: [
		{ locale: "en", name: "English", default: true },
		{ locale: "uk", name: "Українська" },
	],
	pages: [
		{ key: "home", system: true, name: { en: "Home" } },
		{ key: "audit", name: { en: "Audit" } },
	],
};

async function reviewsCms(config: Partial<CmsConfig> = {}) {
	const made = await makeCms(fixture, { blocks: blocks(), collections: [reviews, team], ...config });
	const site = made.cms.site();
	const audit = made.ids.audit as number;
	const anna = await site.entries.create({ collection: "review", status: "published", data: { author: "Anna", text: { en: "Great", uk: "Чудово" }, rating: 5, services: [String(audit)] } });
	const bob = await site.entries.create({ collection: "review", status: "published", data: { author: "Bob", text: { en: "Fine" }, rating: 4 } });
	const draft = await site.entries.create({ collection: "review", data: { author: "Draft", text: { en: "Soon" } } });
	return { ...made, anna, bob, draft, audit };
}

type ReviewsCms = Awaited<ReturnType<typeof reviewsCms>>["cms"];

/** The `r1` block's props on the page at `path`, as the frontend renders it. */
async function renderedProps(cms: ReviewsCms, path: string): Promise<Record<string, unknown>> {
	const result = await cms.loadPage(get(path));
	if (result.type !== "page") {
		throw new Error(result.type);
	}
	const spec = JSON.parse(JSON.stringify(result.props.render)) as { elements: Record<string, { props: Record<string, unknown> }> };
	return spec.elements.r1?.props ?? {};
}

/** The Reviews block's props on `page` for this stored data. */
async function reviewsProps(cms: ReviewsCms, page: number, data: Record<string, unknown>) {
	await cms.site().pages.update(page, { content: [{ id: "r1", kind: "Reviews", data }] });
	return renderedProps(cms, (await cms.site().pageUrl(page, "en")) ?? "/");
}

describe("collections: registry", () => {
	test("the built-in phones, emails and socials are always there, the host's after them", async () => {
		const { cms } = await makeCms(null, { collections: [reviews] });
		expect(cms.collections.all().map((c) => c.key)).toEqual(["phones", "emails", "socials", "review"]);
		expect(cms.collections.get("review")).toMatchObject({ titleField: "author", relatedField: "services", singularLabel: "review" });
		expect(cms.collections.get("socials").titleField).toBe("name");
		expect(() => cms.collections.get("nope")).toThrow("Collection [nope] is not registered.");
	});

	test("a collection field that picks from an unregistered collection fails at startup", async () => {
		await expect(makeCms(null, { blocks: blocks(), collections: [team] })).rejects.toThrow("Block [Reviews]: the collection field [reviews] picks from [review], which is not a registered collection.");
	});

	test("a bad key, a duplicate, a reserved field name and a nested collection field are refused", () => {
		const form = (s: S) => [s.text("name")];
		expect(() => new Collections([{ key: "Bad Key", label: "x", form }])).toThrow("must match");
		expect(() => new Collections([team, team])).toThrow("registered twice");
		expect(() => new Collections([{ key: "x", label: "x", form: (s) => [s.text("status")] }])).toThrow("reserved");
		expect(() => new Collections([{ key: "x", label: "x", form: () => [collectionField("more").of("team")] }])).toThrow("cannot pick entries");
		expect(() => new Collections([{ key: "x", label: "x", form: (s) => [s.text("name"), s.repeater("rows").fields([collectionField("more").of("team")])] }])).toThrow("cannot pick entries");
		expect(() => new Collections([{ key: "x", label: "x", titleField: "nmae", form: (s) => [s.text("name")] }])).toThrow("titleField [nmae] is not a field");
		expect(() => new Collections([{ key: "team-a", label: "A", form: (s) => [s.text("name")] }, { key: "team_a", label: "A", form: (s) => [s.text("name")] }])).toThrow("Collection [team_a] clashes with [team-a]");
		expect(() => new Collections([{ key: "x", label: "x", relatedField: "name", form: (s) => [s.text("name")] }])).toThrow("relatedField [name] is not a pages field");
	});
});

describe("collections: entries and Cms::collection", () => {
	test("published entries in the editor's order, flattened to the locale with the default as fallback", async () => {
		const { cms, anna, bob } = await reviewsCms();
		const en = await cms.site().collection("review");
		expect(en.all()).toEqual([
			{ id: anna, author: "Anna", text: "Great", rating: 5, services: [{ id: expect.any(String), name: "Audit", url: "/audit" }] },
			{ id: bob, author: "Bob", text: "Fine", rating: 4, services: [] },
		]);
		const uk = await cms.site().collection("review", "uk");
		expect(uk.all().map((entry) => entry.text)).toEqual(["Чудово", "Fine"]);
	});

	test("the list filters in memory: latest, relatedTo, only, where, take", async () => {
		const { cms, anna, bob, audit } = await reviewsCms();
		const list = await cms.site().collection("review");
		expect(list.latest(1).all().map((e) => e.id)).toEqual([bob]);
		expect(list.relatedTo(audit).all().map((e) => e.id)).toEqual([anna]);
		expect(list.relatedTo(null).isEmpty()).toBe(true);
		expect(list.only([bob, 999, anna, bob]).all().map((e) => e.id)).toEqual([bob, anna]);
		expect(list.where("rating", 4).first()?.id).toBe(bob);
		expect(list.take(1).count).toBe(1);
	});

	test("the title column follows the title field; a stale lock version is refused", async () => {
		const { cms, anna } = await reviewsCms();
		const site = cms.site();
		expect((await site.entries.find(anna))?.title).toBe("Anna");
		const version = (await site.entries.find(anna))?.lockVersion ?? -1;
		await site.entries.update(anna, { data: { author: "Anna K." } }, { lockVersion: version });
		expect((await site.entries.find(anna))?.title).toBe("Anna K.");
		await expect(site.entries.update(anna, { data: { author: "Late" } }, { lockVersion: version })).rejects.toBeInstanceOf(EntryConflictError);
	});

	test("the cached collection is read once across requests, and every entry write shows on the next one", async () => {
		const { cms, ids, bob } = await reviewsCms({ cache: { store: memoryCache() } });
		const home = ids.home as number;
		await cms.site().pages.update(home, { content: [{ id: "r1", kind: "Reviews", data: {} }] });
		const authors = async () => ((await renderedProps(cms, "/")).reviews as { author: string }[]).map((e) => e.author);
		expect(await authors()).toEqual(["Anna", "Bob"]);
		await cms.config.db.updateTable("tbtop_entries").set({ status: "draft" }).where("id", "=", bob).execute();
		// Written behind the store: the cache still answers.
		expect(await authors()).toEqual(["Anna", "Bob"]);
		await cms.site().entries.update(bob, { status: "published", data: { author: "Bob B." } });
		expect(await authors()).toEqual(["Anna", "Bob B."]);
	});
});

describe("collections: the collection block field", () => {
	test("each mode picks its entries; single gives one or null; drafts never show", async () => {
		const { cms, ids, anna, bob, draft, audit } = await reviewsCms();
		const home = ids.home as number;
		expect(((await reviewsProps(cms, home, {})).reviews as { id: number }[]).map((e) => e.id)).toEqual([anna, bob]);
		expect(((await reviewsProps(cms, home, { reviews: { mode: "latest", limit: 1 } })).reviews as { id: number }[]).map((e) => e.id)).toEqual([bob]);
		expect(((await reviewsProps(cms, home, { reviews: { mode: "manual", ids: [String(draft), String(bob), String(anna)] } })).reviews as { id: number }[]).map((e) => e.id)).toEqual([bob, anna]);
		expect((await reviewsProps(cms, home, { reviews: { mode: "related" } })).reviews).toEqual([]);
		expect(((await reviewsProps(cms, audit, { reviews: { mode: "related" } })).reviews as { id: number }[]).map((e) => e.id)).toEqual([anna]);
		// `featured` allows manual and latest only: the first is the default, an untouched field picks nothing.
		expect((await reviewsProps(cms, home, {})).featured).toBeNull();
		expect(((await reviewsProps(cms, home, { featured: { mode: "latest" } })).featured as { id: number }).id).toBe(bob);
		expect(((await reviewsProps(cms, home, { featured: { mode: "all" } })).featured as { id: number } | null)?.id ?? null).toBeNull();
	});

	test("the field's rules and node: not translatable, the collection_value rule, the client options", () => {
		const field = collectionField("featured").of("review").single().modes(["manual", "latest"]);
		expect(field.ruleEntries()).toEqual(["nullable", "array", "collection_value:review,1,manual+latest"]);
		expect(collectionField("all").of("team").max(4).ruleEntries()).toContain("collection_value:team,4,all+latest+related+manual");
		expect(() => collectionField("x").of("team").translatable()).toThrow("not translatable");
		expect(() => collectionField("x").toNode()).toThrow("needs ->of(");
		const node = collectionField("x").of("team").max(2).toNode();
		expect(node.options).toMatchObject({ allowedModes: ["all", "latest", "related", "manual"], cap: 2, defaultLimit: 2 });
	});
});

/** createAdmin(cms.admin.options()) signed in as user 1: the CMS's own rules and driver. */
async function admin(config: Partial<CmsConfig> = {}) {
	const made = await reviewsCms(config);
	const panel = createAdmin({ ...made.cms.admin.options(), user: () => ({ id: 1, name: "Admin" }) });
	const visit = async (url: string, init: RequestInit & { inertia?: boolean } = {}) => {
		const headers = new Headers(init.headers);
		if (init.inertia !== false) {
			headers.set("x-inertia", "true");
		}
		const res = await panel.handle(new Request(`http://x${url}`, { ...init, headers }));
		if (res === null) {
			throw new TypeError(`${url} fell through`);
		}
		return res;
	};
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	const post = (url: string, body: unknown, referer = url) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${referer}` } });
	const json = async (url: string, body: unknown) => (await (await post(url, body)).json()) as Record<string, unknown>;
	return { ...made, visit, page, post, json };
}

describe("collections: admin", () => {
	const index = "/admin/collections";
	const url = "/admin/collections/review";

	test("every collection is a tab of one page with one menu item; create, edit and delete go through the store", async () => {
		const { cms, page, post, visit, anna } = await admin();
		const props = (await page(`${index}?tab[collections]=review`)).props;
		const nav = JSON.stringify(props);
		expect(nav).toContain('"label":"Collections","href":"/admin/collections"');
		expect(nav).not.toContain('"href":"/admin/collections/review"');
		const tabs = (props.structure as { options: { tabs: { name: string; active?: boolean }[] } }).options.tabs;
		expect(tabs.map((tab) => tab.name)).toEqual(["phones", "emails", "socials", "review", "team"]);
		expect(tabs.filter((tab) => tab.active).map((tab) => tab.name)).toEqual(["review"]);

		const rows = async () => (((await (await visit(`${index}/tables/entries_review`, { inertia: false })).json()) as { data: { data: { title: string }[] } }).data.data).map((row) => row.title);
		expect(await rows()).toEqual(["Anna", "Bob", "Draft"]);

		const created = await post(`${index}/forms/entryCreate_review`, { author: "Cleo", text: { en: "Nice" }, status: "published" });
		expect(created.status).toBe(303);
		const id = Number(/\/review\/(\d+)\/edit/.exec(created.headers.get("location") ?? "")?.[1]);
		expect((await cms.site().entries.find(id))?.sortOrder).toBe(3);

		const edit = `${url}/${anna}/edit`;
		const record = ((await page(edit)).props.data as Record<string, Record<string, unknown>>).entryEditor ?? {};
		expect(record).toMatchObject({ author: "Anna", text: { en: "Great", uk: "Чудово" }, status: "published" });
		await post(`${edit}/forms/entryEditor`, { ...record, author: "Anna K." });
		expect((await cms.site().collection("review")).first()?.author).toBe("Anna K.");
		// The same form again carries the old lock version: refused, nothing written.
		await post(`${edit}/forms/entryEditor`, { ...record, author: "Stale" });
		expect((await cms.site().entries.find(anna))?.title).toBe("Anna K.");

		await post(`${index}/actions/entries_reviewDelete`, { payload: { row: { id: anna } } });
		expect(await cms.site().entries.find(anna)).toBeNull();
		expect((await visit("/admin/collections/review/99999/edit")).status).toBe(404);
	});

	test("a drag reorder writes through the driver and bumps the cached collection", async () => {
		const { cms, ids, post, anna, bob, draft } = await admin({ cache: { store: memoryCache() } });
		await cms.site().pages.update(ids.home as number, { content: [{ id: "r1", kind: "Reviews", data: {} }] });
		const order = async () => ((await renderedProps(cms, "/")).reviews as { id: number }[]).map((e) => e.id);
		expect(await order()).toEqual([anna, bob]);
		const res = await post(`${index}/tables/entries_review/reorder`, { ids: [bob, draft, anna] });
		expect(res.status).toBeLessThan(400);
		expect(await order()).toEqual([bob, anna]);
	});

	test("the entry search lists titles (drafts marked) and names picked ids", async () => {
		const { json, anna, draft } = await admin();
		const endpoint = `${url}/entry-search/select-options/entry`;
		expect(((await json(endpoint, { search: "dra" })).options as { label: string }[]).map((o) => o.label)).toEqual(["Draft (draft)"]);
		expect(((await json(endpoint, { values: [String(draft), String(anna)] })).options as { label: string }[]).map((o) => o.label)).toEqual(["Draft (draft)", "Anna"]);
	});

	test("the collection_value rule: allowed mode, a limit within the cap, ids of that collection", async () => {
		const { cms, anna } = await admin();
		const team = await cms.site().entries.create({ collection: "team", data: { name: "Tim" } });
		const check = (value: unknown, params: string[]) => cms.admin.hostRules.collection_value?.check(value, params, {} as never);
		expect(await check(null, ["review", "0", "all+manual"])).toBe(true);
		expect(await check({ mode: "latest", limit: 2 }, ["review", "0", "all+manual"])).toBe(false);
		expect(await check({ mode: "latest", limit: 5 }, ["review", "3", "latest"])).toBe(false);
		expect(await check({ mode: "manual", ids: [String(anna)] }, ["review", "0", "manual"])).toBe(true);
		expect(await check({ mode: "manual", ids: [String(team)] }, ["review", "0", "manual"])).toBe(false);
		expect(await check({ mode: "manual", ids: [String(anna), "x"] }, ["review", "0", "manual"])).toBe(false);
	});
});

describe("collections: phones, emails and socials", () => {
	test("the first phone and email feed the identity, every social link its sameAs; cms.settings comes from the collections", async () => {
		const { cms } = await makeCms({ languages: [{ locale: "en", name: "English", default: true }], pages: [{ key: "home", system: true, name: { en: "Home" } }] });
		const site = cms.site();
		await site.settings.setMany({ "site.name": "Acme Repair", "seo.identity.type": "local_business", "seo.identity.subtype": "AutoRepair", "seo.identity.address.locality": { en: "Kyiv" } });
		await site.entries.create({ collection: "phones", status: "published", data: { number: "+380 44 000 0001" } });
		await site.entries.create({ collection: "phones", status: "published", data: { number: "+380 44 000 0002" } });
		await site.entries.create({ collection: "phones", data: { number: "+380 44 000 0003" } });
		await site.entries.create({ collection: "emails", status: "published", data: { address: "hi@example.com" } });
		await site.entries.create({ collection: "socials", status: "published", data: { label: "FB", name: "Facebook", href: "https://facebook.com/acme" } });

		const result = await cms.loadPage(get("/"));
		if (result.type !== "page") {
			throw new Error(result.type);
		}
		const identity = (result.props.seo.jsonLd as Record<string, unknown>[]).find((node) => node["@id"] === "https://site.test#identity");
		expect(identity).toMatchObject({
			"@type": "AutoRepair",
			telephone: "+380 44 000 0001",
			email: "hi@example.com",
			address: { "@type": "PostalAddress", addressLocality: "Kyiv" },
			sameAs: ["https://facebook.com/acme"],
		});
		expect(result.props.cms.settings).toMatchObject({
			phones: ["+380 44 000 0001", "+380 44 000 0002"],
			emails: ["hi@example.com"],
			socials: [{ label: "FB", name: "Facebook", href: "https://facebook.com/acme" }],
		});
		expect(await site.contacts.phones()).toEqual(["+380 44 000 0001", "+380 44 000 0002"]);
	});

	test("a phone needs its number, an email a valid address, a social link its label and an http(s) link", () => {
		const built = new Collections(BUILT_IN_COLLECTIONS);
		const rules = (fields: unknown[], name: string) => (fields.find((field) => (field as { name: string }).name === name) as { ruleEntries(): string[] }).ruleEntries();
		expect(rules(built.get("phones").form(new S()), "number")).toContain("required");
		expect(rules(built.get("emails").form(new S()), "address")).toEqual(expect.arrayContaining(["required", "email"]));
		expect(rules(built.get("socials").form(new S()), "label")).toEqual(expect.arrayContaining(["required", "max:8"]));
		expect(rules(built.get("socials").form(new S()), "href")).toEqual(expect.arrayContaining(["required", "url"]));
	});
	test("a social link with a script scheme is refused by the form and never reaches the site", async () => {
		const socials = new Collections(BUILT_IN_COLLECTIONS).get("socials");
		const href = (socials.form(new S()) as { name: string; ruleEntries(): string[] }[]).find((field) => field.name === "href")?.ruleEntries() ?? [];
		const pattern = href.find((rule) => rule.startsWith("regex:"))?.slice("regex:".length) ?? "";
		const regex = new RegExp(pattern.slice(1, pattern.lastIndexOf("/")), pattern.slice(pattern.lastIndexOf("/") + 1));
		expect(regex.test("javascript://%0Aalert(1)")).toBe(false);
		expect(regex.test("https://t.me/acme")).toBe(true);

		const { cms } = await makeCms({ languages: [{ locale: "en", name: "English", default: true }] });
		const site = cms.site();
		await site.entries.create({ collection: "socials", status: "published", data: { label: "X", href: "javascript://%0Aalert(1)" } });
		await site.entries.create({ collection: "socials", status: "published", data: { label: "TG", href: "https://t.me/acme" } });
		expect((await site.contacts.socials()).map((social) => social.href)).toEqual(["https://t.me/acme"]);
	});
});

describe("collections: the site file", () => {
	test("collection() in defineSite, picked with f.collection(): typed props, the key from the site", () => {
		const people = collection({ name: f.text().required(), photo: f.image() }, { label: "Team" });
		const site = defineSite({
			collections: { team: people },
			blocks: { Team: block({ members: f.collection(people, { max: 8 }), lead: f.collection(people, { single: true }), phones: f.collection("phones") }) },
		});
		const props = site.catalog.Team.props;
		expect(props.safeParse({ members: [{ id: 1, name: "Ann", photo: null }], lead: null, phones: [{ id: 2, anything: true }] }).success).toBe(true);
		expect(props.safeParse({ members: [{ name: "Ann", photo: null }], lead: null, phones: [] }).success).toBe(false);

		const cms = { link: () => null, pages: () => null, collection: collectionField } as never;
		const [def] = collectionDefs(site.def, cms);
		expect(def?.key).toBe("team");
		expect(def?.form(new S()).map((field) => (field as { name: string }).name)).toEqual(["name", "photo"]);
		const built = f.collection(people, { single: true }).build(new S(), cms, "lead") as ReturnType<typeof collectionField>;
		expect(built.collectionKey()).toBe("team");
		expect(built.isSingle()).toBe(true);
		expect(() => f.collection(collection({ a: f.text() }, { label: "Lost" })).build(new S(), cms, "x")).toThrow("not in defineSite");
	});
});
