import { appendFile, readFile } from "node:fs/promises";
import { createAdmin, rootView, type ViteManifest, viteTags } from "@tbtop/admin";
import { kyselyDriver, kyselyNotifications, kyselyRules } from "@tbtop/admin/kysely";
import { localDisk } from "@tbtop/admin/local-disk";
import { type CmsDatabase, type Fixture, type MailMessage, cmsBlockImpls, createCms, demoFixture, ensureErrorPages, implementBlocks, linkField, migrateCms, seedFixtures } from "@tbtop/cms";
import Database from "better-sqlite3";
import { Kysely, SqliteDialect } from "kysely";
import { catalog } from "../blocks/catalog";

// Server-only wiring of the workbench host: one SQLite file, the CMS, its
// admin panel. Everything here is what a real TanStack Start host writes.

/** Uploaded files, served at /storage by routes/storage/$.ts. */
export const STORAGE = process.env.CMS_STORAGE ?? "storage";

const db = new Kysely<CmsDatabase>({
	dialect: new SqliteDialect({ database: new Database(process.env.CMS_DB ?? "workbench.sqlite") }),
});

/** Sent mail, one JSON line per message: the workbench has no SMTP. */
export const MAILBOX = process.env.CMS_MAILBOX ?? "mailbox.jsonl";

const bell = kyselyNotifications(db);

/** The demo site, with a Promo block under the home page's hero. */
const fixture: Fixture = {
	...demoFixture,
	pages: demoFixture.pages?.map((page) =>
		page.key === "home"
			? { ...page, blocks: [...(page.blocks ?? []).slice(0, 1), { kind: "Promo", data: { title: { en: "Who builds this?", uk: "Хто це робить?" } } }, ...(page.blocks ?? []).slice(1)] }
			: page,
	),
};

const cms = createCms({
	db,
	blocks: implementBlocks(catalog, {
		...cmsBlockImpls,
		// No resolve: FormResolver turns the stored link into { href, type, label } or null.
		Promo: { form: (s) => [s.text("title").label("Title").translatable(), linkField("cta").label("Button").types(["page", "external"])] },
	}),
	// Drafts open through the page editor's signed "Preview" links. A host sets
	// its own secret (and canPreview for its signed-in editors).
	preview: { secret: process.env.CMS_PREVIEW_SECRET ?? "workbench-preview-secret" },
	// Kysely's unique/exists for the form submits; the admin gets them back in cms.admin.hostRules.
	hostRules: kyselyRules(db),
	// E2E posts its forms from one client, which the 10-a-minute throttle would count.
	forms: process.env.CMS_FORMS_THROTTLE === "off" ? { throttle: false } : {},
	notifications: {
		mail: { send: async (message: MailMessage) => appendFile(MAILBOX, `${JSON.stringify(message)}\n`) },
		bell: { store: bell, staff: () => [{ id: 1 }] },
	},
});

async function adminAssets(): Promise<string> {
	const manifest = JSON.parse(await readFile("public/admin-build/.vite/manifest.json", "utf8")) as ViteManifest;
	return viteTags({ entry: "src/admin-client.tsx", manifest, base: "/admin-build/" });
}

async function boot() {
	await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
	await seedFixtures(cms, fixture);
	// The 404 and 5xx pages an editor keeps in the pages list, as cms:install creates them.
	await ensureErrorPages(cms);
	const admin = createAdmin({
		prefix: "/admin",
		brand: "tbtop cms",
		pages: cms.admin.pages,
		navigationGroups: cms.admin.navigationGroups,
		driver: kyselyDriver(db),
		// The config's hostRules plus link_shape and page_ref, which check the link and pages fields on save.
		hostRules: cms.admin.hostRules,
		// The workbench signs everyone in, as the PHP workbench does.
		user: () => ({ id: 1, name: "Workbench" }),
		context: cms.admin.context,
		// The CMS upload fields write to "public"; its refs read back as /storage/{ref}.
		disks: { public: localDisk(STORAGE, "/storage") },
		notifications: { store: bell },
		rootView: rootView({ head: await adminAssets() }),
	});
	return { cms, admin };
}

let booted: ReturnType<typeof boot> | null = null;

/** The CMS and its admin panel, once the schema and the demo fixture are in place. */
export function site() {
	booted ??= boot();
	return booted;
}
