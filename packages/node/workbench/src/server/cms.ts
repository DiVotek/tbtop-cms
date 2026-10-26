import { appendFile, readFile } from "node:fs/promises";
import { createAdmin, rootView, type ViteManifest, viteTags } from "@tbtop/admin";
import { kyselyDriver, kyselyNotifications, kyselyRules } from "@tbtop/admin/kysely";
import { localDisk } from "@tbtop/admin/local-disk";
import { type CmsDatabase, type Fixture, type MailMessage, cmsBlockImpls, createCms, demoFixture, ensureErrorPages, implementBlocks, linkField, migrateCms, seedFixtures } from "@tbtop/cms";
import { cmsAccess, cmsAuth, cmsRoles } from "@tbtop/cms/better-auth";
import { betterAuth } from "better-auth";
import { getMigrations } from "better-auth/db/migration";
import { admin as adminPlugin } from "better-auth/plugins";
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

// Better Auth on the CMS's own database, its tables prefixed like the CMS's.
// Roles come from the CMS's access control: admin, editor, user (no panel).
const authOptions = {
	database: { db: db as Kysely<unknown>, type: "sqlite" as const },
	secret: process.env.BETTER_AUTH_SECRET ?? "workbench-secret-workbench-secret",
	baseURL: process.env.BETTER_AUTH_URL ?? "http://localhost:3000",
	emailAndPassword: { enabled: true },
	user: { modelName: "tbtop_users" },
	session: { modelName: "tbtop_sessions" },
	account: { modelName: "tbtop_accounts" },
	verification: { modelName: "tbtop_verifications" },
	plugins: [adminPlugin({ ac: cmsAccess, roles: cmsRoles })],
};

/** The host's Better Auth instance; routes/api/auth/$.ts serves its HTTP API. */
export const auth = betterAuth(authOptions);
const panelAuth = cmsAuth(auth);

/** The seeded admin (admin@admin.com / password, as in the PHP workbench): the bell's only staff user. */
let adminId = "";

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
	// Section access from the signed-in user's Better Auth role.
	authorize: panelAuth.authorize,
	// The workbench is where catalog drift should be loud; E2E serves a production build and drops such a block.
	strictBlocks: process.env.NODE_ENV !== "production",
	// E2E posts its forms from one client, which the 10-a-minute throttle would count.
	forms: process.env.CMS_FORMS_THROTTLE === "off" ? { throttle: false } : {},
	notifications: {
		mail: { send: async (message: MailMessage) => appendFile(MAILBOX, `${JSON.stringify(message)}\n`) },
		bell: { store: bell, staff: () => [{ id: adminId }] },
	},
});

async function adminAssets(): Promise<string> {
	const manifest = JSON.parse(await readFile("public/admin-build/.vite/manifest.json", "utf8")) as ViteManifest;
	return viteTags({ entry: "src/admin-client.tsx", manifest, base: "/admin-build/" });
}

async function boot() {
	// Better Auth's ids are strings, so the CMS's user columns are too.
	await migrateCms(db as Kysely<unknown>, { dialect: "sqlite", userKey: "string" });
	await (await getMigrations(authOptions)).runMigrations();
	adminId = await seedUser("admin@admin.com", "Admin", "admin");
	await seedUser("editor@editor.com", "Editor", "editor");
	await seedFixtures(cms, fixture);
	// The 404 and 5xx pages an editor keeps in the pages list, as cms:install creates them.
	await ensureErrorPages(cms);
	const admin = createAdmin({
		// The CMS panel's look: brand, topbar-sidebar, compact, nav groups, chrome (as PHP's CmsPanel).
		...cms.admin.panel,
		prefix: "/admin",
		pages: [...cms.admin.pages, ...panelAuth.pages(cms)],
		driver: kyselyDriver(db),
		// The config's hostRules plus link_shape and page_ref, which check the link and pages fields on save.
		hostRules: cms.admin.hostRules,
		// The panel's user is Better Auth's session user; guests go to the sign-in page.
		session: panelAuth.session,
		loginPath: panelAuth.loginPath(cms),
		context: cms.admin.context,
		// The CMS upload fields write to "public"; its refs read back as /storage/{ref}.
		disks: { public: localDisk(STORAGE, "/storage") },
		notifications: { store: bell },
		rootView: rootView({ head: await adminAssets() }),
	});
	return { cms, admin };
}

/** A seeded account, password `password`; its id when it already exists. */
async function seedUser(email: string, name: string, role: "admin" | "editor"): Promise<string> {
	const found = await (db as unknown as Kysely<{ tbtop_users: { id: string; email: string } }>).selectFrom("tbtop_users").select("id").where("email", "=", email).executeTakeFirst();
	return found?.id ?? (await auth.api.createUser({ body: { email, password: "password", name, role } })).user.id;
}

let booted: ReturnType<typeof boot> | null = null;

/** The CMS and its admin panel, once the schema and the demo fixture are in place. */
export function site() {
	booted ??= boot();
	return booted;
}
