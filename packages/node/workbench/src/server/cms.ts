import { readFile } from "node:fs/promises";
import { createAdmin, rootView, type ViteManifest, viteTags } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { localDisk } from "@tbtop/admin/local-disk";
import { type CmsDatabase, cmsBlockImpls, cmsBlocks, createCms, demoFixture, implementBlocks, migrateCms, seedFixtures } from "@tbtop/cms";
import Database from "better-sqlite3";
import { Kysely, SqliteDialect } from "kysely";

// Server-only wiring of the workbench host: one SQLite file, the CMS, its
// admin panel. Everything here is what a real TanStack Start host writes.

/** Uploaded files, served at /storage by routes/storage/$.ts. */
export const STORAGE = process.env.CMS_STORAGE ?? "storage";

const db = new Kysely<CmsDatabase>({
	dialect: new SqliteDialect({ database: new Database(process.env.CMS_DB ?? "workbench.sqlite") }),
});

const cms = createCms({
	db,
	blocks: implementBlocks(cmsBlocks, cmsBlockImpls),
	// The PHP host previews for signed-in editors; the workbench takes `?preview`.
	canPreview: (request) => new URL(request.url).searchParams.has("preview"),
});

async function adminAssets(): Promise<string> {
	const manifest = JSON.parse(await readFile("public/admin-build/.vite/manifest.json", "utf8")) as ViteManifest;
	return viteTags({ entry: "src/admin-client.tsx", manifest, base: "/admin-build/" });
}

async function boot() {
	await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
	await seedFixtures(cms, demoFixture);
	const admin = createAdmin({
		prefix: "/admin",
		brand: "tbtop cms",
		pages: cms.admin.pages,
		navigationGroups: cms.admin.navigationGroups,
		driver: kyselyDriver(db),
		hostRules: kyselyRules(db),
		// The workbench signs everyone in, as the PHP workbench does.
		user: () => ({ id: 1, name: "Workbench" }),
		context: cms.admin.context,
		// The CMS upload fields write to "public"; its refs read back as /storage/{ref}.
		disks: { public: localDisk(STORAGE, "/storage") },
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
