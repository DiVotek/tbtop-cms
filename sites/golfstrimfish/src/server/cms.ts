import { appendFile, readFile } from "node:fs/promises";
import { createAdmin, rootView, type ViteManifest, viteTags } from "@tbtop/admin";
import { kyselyDriver, kyselyNotifications, kyselyRules } from "@tbtop/admin/kysely";
import { localDisk } from "@tbtop/admin/local-disk";
import { type CmsDatabase, type MailMessage, createCms, ensureErrorPages, migrateCms } from "@tbtop/cms";
import { cmsAccess, cmsAuth, cmsRoles } from "@tbtop/cms/better-auth";
import { betterAuth } from "better-auth";
import { getMigrations } from "better-auth/db/migration";
import { admin as adminPlugin } from "better-auth/plugins";
import Database from "better-sqlite3";
import { Kysely, SqliteDialect } from "kysely";
import { siteBlocks } from "../blocks/implementations";
import { seedGolfstrimSite } from "../fixture/seedSite";
import { productType } from "../pageTypes/product";

export const STORAGE = process.env.CMS_STORAGE ?? "storage";
export const MAILBOX = process.env.CMS_MAILBOX ?? "mailbox.jsonl";

const db = new Kysely<CmsDatabase>({
	dialect: new SqliteDialect({ database: new Database(process.env.CMS_DB ?? "golfstrim.sqlite") }),
});

const authOptions = {
	database: { db: db as Kysely<unknown>, type: "sqlite" as const },
	secret: process.env.BETTER_AUTH_SECRET ?? "golfstrim-dev-secret-golfstrim-dev-secret",
	baseURL: process.env.BETTER_AUTH_URL ?? "http://localhost:3300",
	emailAndPassword: { enabled: true },
	user: { modelName: "tbtop_users" },
	session: { modelName: "tbtop_sessions" },
	account: { modelName: "tbtop_accounts" },
	verification: { modelName: "tbtop_verifications" },
	plugins: [adminPlugin({ ac: cmsAccess, roles: cmsRoles })],
};

export const auth = betterAuth(authOptions);
const panelAuth = cmsAuth(auth);

let adminId = "";

const cms = createCms({
	db,
	blocks: siteBlocks,
	pageTypes: [productType],
	url: process.env.CMS_URL ?? "http://localhost:3300",
	media: (ref) => ({ url: `/storage/${ref}` }),
	preview: { secret: process.env.CMS_PREVIEW_SECRET ?? "golfstrim-preview-secret-golfstrim" },
	hostRules: kyselyRules(db),
	authorize: panelAuth.authorize,
	forms: process.env.CMS_FORMS_THROTTLE === "off" ? { throttle: false } : {},
	notifications: {
		mail: { send: async (message: MailMessage) => appendFile(MAILBOX, `${JSON.stringify(message)}\n`) },
		bell: { store: kyselyNotifications(db), staff: () => [{ id: adminId }] },
	},
});

async function adminAssets(): Promise<string> {
	const manifest = JSON.parse(await readFile("public/admin-build/.vite/manifest.json", "utf8")) as ViteManifest;
	return viteTags({ entry: "src/admin-client.tsx", manifest, base: "/admin-build/" });
}

async function boot() {
	await migrateCms(db as Kysely<unknown>, { dialect: "sqlite", userKey: "string", defaultLanguage: "uk" });
	await (await getMigrations(authOptions)).runMigrations();
	adminId = await seedUser("admin@admin.com", "Admin", "admin");
	await seedUser("editor@editor.com", "Editor", "editor");
	await seedGolfstrimSite(cms);
	await ensureErrorPages(cms);
	const admin = createAdmin({
		...cms.admin.panel,
		prefix: "/admin",
		pages: [...cms.admin.pages, ...panelAuth.pages(cms)],
		driver: kyselyDriver(db),
		hostRules: cms.admin.hostRules,
		session: panelAuth.session,
		loginPath: panelAuth.loginPath(cms),
		context: cms.admin.context,
		disks: { public: localDisk(STORAGE, "/storage") },
		notifications: { store: kyselyNotifications(db) },
		rootView: rootView({ head: await adminAssets() }),
	});
	return { cms, admin };
}

async function seedUser(email: string, name: string, role: "admin" | "editor"): Promise<string> {
	const found = await (db as unknown as Kysely<{ tbtop_users: { id: string; email: string } }>).selectFrom("tbtop_users").select("id").where("email", "=", email).executeTakeFirst();
	return found?.id ?? (await auth.api.createUser({ body: { email, password: "password", name, role } })).user.id;
}

let booted: ReturnType<typeof boot> | null = null;

export function site() {
	booted ??= boot();
	return booted;
}
