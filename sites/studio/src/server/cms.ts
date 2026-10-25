import { appendFile, readFile } from "node:fs/promises";
import { createAdmin, definePage, rootView, type ViteManifest, viteTags } from "@tbtop/admin";
import { kyselyDriver, kyselyNotifications, kyselyRules } from "@tbtop/admin/kysely";
import { localDisk } from "@tbtop/admin/local-disk";
import {
	type CmsDatabase,
	type Fixture,
	type MailMessage,
	cmsBlockImpls,
	createCms,
	definePageType,
	ensureErrorPages,
	implementBlocks,
	migrateCms,
	seedFixtures,
} from "@tbtop/cms";

function safeHref(value: string | null | undefined): string | null {
	if (value == null || value.trim() === "") {
		return null;
	}
	const parsed = value.replace(/^[\x00-\x20]+/, "").replace(/[\t\n\r]/g, "");
	const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(parsed)?.[1]?.toLowerCase();
	if (scheme === undefined) {
		return value;
	}
	return ["http", "https", "mailto", "tel"].includes(scheme) ? value : null;
}
import { cmsAccess, cmsAuth, cmsRoles } from "@tbtop/cms/better-auth";
import { betterAuth } from "better-auth";
import { getMigrations } from "better-auth/db/migration";
import { admin as adminPlugin } from "better-auth/plugins";
import Database from "better-sqlite3";
import { Kysely, SqliteDialect } from "kysely";
import { z } from "zod";
import { catalog } from "../blocks/catalog";
import { lumenFixture } from "../fixtures/lumen";
import { pickLocaleList } from "./localePick";
import { fieldText } from "./translatable";

export const STORAGE = process.env.CMS_STORAGE ?? "storage";
export const MAILBOX = process.env.CMS_MAILBOX ?? "mailbox.jsonl";

const db = new Kysely<CmsDatabase>({
	dialect: new SqliteDialect({ database: new Database(process.env.CMS_DB ?? "studio.sqlite") }),
});

const bell = kyselyNotifications(db);

const journalType = definePageType({
	key: "journal",
	base: "article",
	label: "Journal post",
	fields: z.object({
		tags: z.array(z.string()).default([]),
		readingMinutes: z.number().nullable(),
	}),
	form: (s) => [s.tags("tags").label("Tags"), s.number("readingMinutes").label("Reading time (min)")],
});

const authOptions = {
	database: { db: db as Kysely<unknown>, type: "sqlite" as const },
	secret: process.env.BETTER_AUTH_SECRET ?? "lumen-studio-secret-lumen-studio-secret",
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
	url: process.env.CMS_URL ?? "http://localhost:3300",
	pageTypes: [journalType],
	blocks: implementBlocks(catalog, {
		...cmsBlockImpls,
		SiteNav: { form: () => [], resolve: () => ({}) },
		SiteFooter: { form: () => [], resolve: () => ({}) },
		StudioHero: {
			form: (s) => [
				s.text("eyebrow").label("Eyebrow").translatable(),
				s.text("title").label("Title").translatable().required(),
				s.textarea("subtitle").label("Subtitle").translatable(),
				s.text("ctaLabel").label("Primary CTA label").translatable(),
				s.text("ctaHref").label("Primary CTA URL").rules(["nullable"]),
				s.text("secondaryLabel").label("Secondary CTA label").translatable(),
				s.text("secondaryHref").label("Secondary CTA URL").rules(["nullable"]),
			],
			resolve: (input, ctx) => {
				const mk = (prefix: string) => {
					const l = fieldText(input, `${prefix}Label`, ctx.locale, ctx.fallbackLocale);
					const h = safeHref(String(input[`${prefix}Href`] ?? ""));
					return l && h ? { label: l, href: h, type: "external" as const } : null;
				};
				return {
					eyebrow: fieldText(input, "eyebrow", ctx.locale, ctx.fallbackLocale),
					title: fieldText(input, "title", ctx.locale, ctx.fallbackLocale),
					subtitle: fieldText(input, "subtitle", ctx.locale, ctx.fallbackLocale),
					cta: mk("cta"),
					secondary: mk("secondary"),
				};
			},
		},
		ServiceGrid: {
			form: (s) => [s.text("heading").label("Heading").translatable(), s.textarea("items").label("Items JSON per locale").helperText("Internal: seeded JSON")],
			resolve: (input, ctx) => ({
				heading: fieldText(input, "heading", ctx.locale, ctx.fallbackLocale),
				items: pickLocaleList<{ title: string; body: string }>(input.items, ctx.locale, ctx.fallbackLocale),
			}),
		},
		StatBand: {
			form: (s) => [s.textarea("stats").label("Stats JSON per locale")],
			resolve: (input, ctx) => ({ stats: pickLocaleList<{ value: string; label: string }>(input.stats, ctx.locale, ctx.fallbackLocale) }),
		},
		TeamStrip: {
			form: (s) => [s.text("heading").label("Heading").translatable()],
			resolve: (input, ctx) => ({
				heading: fieldText(input, "heading", ctx.locale, ctx.fallbackLocale),
				members: [
					{ name: "Mira Chen", role: ctx.locale === "de" ? "Design Lead" : "Design lead" },
					{ name: "Jonas Weber", role: ctx.locale === "de" ? "Engineering" : "Engineering" },
					{ name: "Aya Okonkwo", role: ctx.locale === "de" ? "Produkt" : "Product" },
				],
			}),
		},
		CaseStudyList: {
			form: (s) => [s.textarea("cases").label("Cases JSON per locale")],
			resolve: (input, ctx) => ({ cases: pickLocaleList<{ title: string; tag: string; href: string }>(input.cases, ctx.locale, ctx.fallbackLocale) }),
		},
		PullQuote: {
			form: (s) => [s.textarea("quote").label("Quote").translatable(), s.text("attribution").label("Attribution").translatable()],
			resolve: (input, ctx) => ({
				quote: fieldText(input, "quote", ctx.locale, ctx.fallbackLocale),
				attribution: fieldText(input, "attribution", ctx.locale, ctx.fallbackLocale),
			}),
		},
		Newsletter: {
			form: (s) => [
				s.text("heading").label("Heading").translatable(),
				s.textarea("description").label("Description").translatable(),
				s.text("submitLabel").label("Submit label").translatable(),
				s.textarea("successMessage").label("Success message").translatable(),
			],
			resolve: (input, ctx) => ({
				heading: fieldText(input, "heading", ctx.locale, ctx.fallbackLocale) ?? ctx.t("forms.contact.heading"),
				description: fieldText(input, "description", ctx.locale, ctx.fallbackLocale),
				submitLabel: fieldText(input, "submitLabel", ctx.locale, ctx.fallbackLocale) ?? "Subscribe",
				successMessage: fieldText(input, "successMessage", ctx.locale, ctx.fallbackLocale) ?? "Thanks — check your inbox.",
				action: "",
				honeypotField: null,
			}),
			submit: {
				rules: () => ({ email: "required|email|max:200" }),
			},
		},
	}),
	preview: { secret: process.env.CMS_PREVIEW_SECRET ?? "lumen-preview-secret-lumen-preview" },
	hostRules: kyselyRules(db),
	authorize: panelAuth.authorize,
	forms: process.env.CMS_FORMS_THROTTLE === "off" ? { throttle: false } : {},
	notifications: {
		mail: { send: async (message: MailMessage) => appendFile(MAILBOX, `${JSON.stringify(message)}\n`) },
		bell: { store: bell, staff: () => [{ id: adminId }] },
	},
	revisions: { keep: 25 },
});

async function adminAssets(): Promise<string> {
	const manifest = JSON.parse(await readFile("public/admin-build/.vite/manifest.json", "utf8")) as ViteManifest;
	return viteTags({ entry: "src/admin-client.tsx", manifest, base: "/admin-build/" });
}

function studioOverviewPage() {
	return definePage({
		path: "studio-overview",
		title: "Studio overview",
		nav: { group: "Content", label: "Studio overview", icon: "layout-grid", order: 5 },
		view: async (s) => {
			const siteApi = cms.site();
			const pages = await siteApi.pages.query().select("id").execute();
			const live = await siteApi.pages.query().select("id").where("status", "=", "published").execute();
			const locale = await siteApi.languages.defaultLocale();
			return s.stack([
				s.displayAlert(`Dogfood snapshot: ${pages.length} pages (${live.length} published), default locale ${locale}.`).title("Studio snapshot"),
			]);
		},
	});
}

async function boot() {
	await migrateCms(db as Kysely<unknown>, { dialect: "sqlite", userKey: "string" });
	await (await getMigrations(authOptions)).runMigrations();
	adminId = await seedUser("admin@admin.com", "Admin", "admin");
	await seedUser("editor@editor.com", "Editor", "editor");
	const ids = await seedFixtures(cms, lumenFixture as Fixture);
	await ensureErrorPages(cms);
	await ensureSlugRedirectDemo(ids);
	const admin = createAdmin({
		...cms.admin.panel,
		brand: "Lumen Studio",
		prefix: "/admin",
		pages: [...cms.admin.pages, studioOverviewPage(), ...panelAuth.pages(cms)],
		driver: kyselyDriver(db),
		hostRules: cms.admin.hostRules,
		session: panelAuth.session,
		loginPath: panelAuth.loginPath(cms),
		context: cms.admin.context,
		disks: { public: localDisk(STORAGE, "/storage") },
		notifications: { store: bell },
		rootView: rootView({ head: await adminAssets() }),
	});
	return { cms, admin };
}

/** After seed: rename Contact slug so CMS records an auto-redirect from /contact. */
async function ensureSlugRedirectDemo(ids: Record<string, number>) {
	const contactId = ids.contact;
	if (contactId === undefined) {
		return;
	}
	const site = cms.site();
	const row = await site.pages.find(contactId);
	if (row === null) {
		return;
	}
	await site.pages.update(contactId, {}, { slugs: { en: "get-in-touch", de: "kontakt" }, lockVersion: row.lockVersion });
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

export { cms, db };
