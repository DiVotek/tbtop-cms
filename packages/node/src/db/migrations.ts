import {
	type ColumnDataType,
	type CreateTableBuilder,
	type Kysely,
	type Migration,
	type MigrationProvider,
	Migrator,
	sql,
} from "kysely";
import { ensureDefaultLanguage } from "../content/languages";
import type { CmsDatabase } from "./schema";

export type Dialect = "sqlite" | "postgres" | "mysql";

export type MigrationOptions = {
	dialect: Dialect;
	/** The host's user key type: Laravel's `UserKeyColumn` (int ids, or uuid/ulid strings). */
	userKey?: "int" | "string";
};

type Table = CreateTableBuilder<string, any>;

/** Column types per dialect, as Laravel's schema grammar writes them. */
function types(dialect: Dialect) {
	const sqlite = dialect === "sqlite";
	return {
		id: (sqlite ? "integer" : dialect === "postgres" ? "bigserial" : "bigint") as ColumnDataType,
		bigint: (sqlite ? "integer" : "bigint") as ColumnDataType,
		json: (sqlite ? "text" : "json") as ColumnDataType,
		bool: (sqlite ? "integer" : "boolean") as ColumnDataType,
		stamp: (sqlite ? "datetime" : "timestamp") as ColumnDataType,
	};
}

/**
 * The baseline: every table the 38 PHP migrations leave behind, in one step.
 * A database the PHP package already migrated has these tables; run this on
 * a fresh one only.
 */
export function baselineMigration(options: MigrationOptions): Migration {
	const t = types(options.dialect);
	const user = (options.userKey ?? "int") === "int" ? t.bigint : ("varchar(36)" as ColumnDataType);
	const id = (table: Table) =>
		table.addColumn("id", t.id, (c) => {
			const key = c.primaryKey();
			return options.dialect === "postgres" ? key : key.autoIncrement();
		});
	const stamps = (table: Table) =>
		table.addColumn("created_at", t.stamp).addColumn("updated_at", t.stamp);
	const bool = (value: boolean) => (options.dialect === "postgres" ? sql`${sql.lit(value)}` : sql`${sql.lit(value ? 1 : 0)}`);

	return {
		async up(db: Kysely<unknown>) {
			const s = db.schema;
			await stamps(
				id(s.createTable("tbtop_languages"))
					.addColumn("name", "varchar(255)", (c) => c.notNull())
					.addColumn("locale", "varchar(5)", (c) => c.notNull().unique())
					.addColumn("og_locale", "varchar(10)")
					.addColumn("is_default", t.bool, (c) => c.notNull().defaultTo(bool(false)))
					.addColumn("is_admin_active", t.bool, (c) => c.notNull().defaultTo(bool(false)))
					.addColumn("is_frontend_active", t.bool, (c) => c.notNull().defaultTo(bool(false)))
					.addColumn("sort_order", "integer", (c) => c.notNull().defaultTo(0)),
			)
				.addColumn("deleted_at", t.stamp)
				.execute();

			await stamps(
				id(s.createTable("tbtop_pages"))
					.addColumn("type", "varchar(255)", (c) => c.notNull())
					.addColumn("subtype", "varchar(255)")
					.addColumn("source_id", "varchar(255)")
					.addColumn("parent_id", t.bigint, (c) => c.references("tbtop_pages.id"))
					.addColumn("status", "varchar(255)", (c) => c.notNull().defaultTo("draft"))
					.addColumn("published_at", t.stamp)
					.addColumn("unpublish_at", t.stamp)
					.addColumn("content", t.json)
					.addColumn("fields", t.json)
					.addColumn("starter_blocks", t.json)
					.addColumn("name", t.json)
					.addColumn("title", t.json)
					.addColumn("heading", t.json)
					.addColumn("summary", t.json)
					.addColumn("body", t.json)
					.addColumn("image", "varchar(255)")
					.addColumn("seo", t.json)
					.addColumn("is_indexable", t.bool, (c) => c.notNull().defaultTo(bool(true)))
					.addColumn("is_system", t.bool, (c) => c.notNull().defaultTo(bool(false)))
					.addColumn("error_class", "varchar(255)", (c) => c.unique())
					.addColumn("sort_order", "integer", (c) => c.notNull().defaultTo(0))
					.addColumn("lock_version", "integer", (c) => c.notNull().defaultTo(0))
					.addColumn("created_by", user)
					.addColumn("updated_by", user),
			)
				.addColumn("deleted_at", t.stamp)
				.execute();
			await s.createIndex("tbtop_pages_type_index").on("tbtop_pages").column("type").execute();
			await s.createIndex("tbtop_pages_subtype_index").on("tbtop_pages").column("subtype").execute();
			await s
				.createIndex("tbtop_pages_type_source_id_index")
				.on("tbtop_pages")
				.columns(["type", "source_id"])
				.execute();
			await s
				.createIndex("tbtop_pages_parent_id_sort_order_index")
				.on("tbtop_pages")
				.columns(["parent_id", "sort_order"])
				.execute();

			await stamps(
				id(s.createTable("tbtop_page_slugs"))
					.addColumn("page_id", t.bigint, (c) => c.notNull())
					.addColumn("locale", "varchar(5)", (c) => c.notNull())
					.addColumn("slug", "varchar(160)", (c) => c.notNull())
					.addColumn("path", "varchar(600)", (c) => c.notNull())
					.addUniqueConstraint("tbtop_page_slugs_page_id_locale_unique", ["page_id", "locale"])
					.addUniqueConstraint("tbtop_page_slugs_locale_path_unique", ["locale", "path"]),
			).execute();

			await stamps(
				id(s.createTable("tbtop_user_profiles"))
					.addColumn("user_id", user, (c) => c.notNull().unique())
					.addColumn("is_owner", t.bool, (c) => c.notNull().defaultTo(bool(false)))
					.addColumn("locale", "varchar(255)")
					.addColumn("avatar_media_id", "varchar(255)")
					.addColumn("display_name", "varchar(255)"),
			).execute();

			await stamps(
				id(s.createTable("tbtop_redirects"))
					.addColumn("locale", "varchar(5)")
					.addColumn("from_path", "varchar(600)", (c) => c.notNull())
					.addColumn("to_path", "varchar(2048)", (c) => c.notNull())
					.addColumn("status_code", "integer", (c) => c.notNull().defaultTo(301))
					.addColumn("is_auto", t.bool, (c) => c.notNull().defaultTo(bool(false)))
					.addColumn("created_by", user)
					.addColumn("updated_by", user)
					.addUniqueConstraint("tbtop_redirects_locale_from_path_unique", ["locale", "from_path"]),
			).execute();

			await stamps(
				id(s.createTable("tbtop_settings"))
					.addColumn("key", "varchar(255)", (c) => c.notNull().unique())
					.addColumn("value", "text"),
			).execute();

			await stamps(
				id(s.createTable("tbtop_form_submissions"))
					.addColumn("page_id", t.bigint, (c) => c.references("tbtop_pages.id").onDelete("set null"))
					.addColumn("block_id", "varchar(255)", (c) => c.notNull())
					.addColumn("kind", "varchar(255)", (c) => c.notNull())
					.addColumn("locale", "varchar(255)", (c) => c.notNull())
					.addColumn("payload", t.json, (c) => c.notNull())
					.addColumn("payload_order", t.json)
					.addColumn("status", "varchar(255)", (c) => c.notNull())
					.addColumn("lead_status", "varchar(255)", (c) => c.notNull().defaultTo("new"))
					.addColumn("error", "text")
					.addColumn("handled_at", t.stamp),
			).execute();
			await s
				.createIndex("tbtop_form_submissions_lead_status_index")
				.on("tbtop_form_submissions")
				.column("lead_status")
				.execute();

			await stamps(
				id(s.createTable("tbtop_form_submission_comments"))
					.addColumn("submission_id", t.bigint, (c) =>
						c.notNull().references("tbtop_form_submissions.id").onDelete("cascade"),
					)
					.addColumn("user_id", user, (c) => c.notNull())
					.addColumn("body", "text", (c) => c.notNull()),
			).execute();

			await stamps(
				id(s.createTable("tbtop_layouts"))
					.addColumn("slot", "varchar(255)", (c) => c.notNull().unique())
					.addColumn("content", t.json),
			).execute();

			await id(s.createTable("tbtop_page_revisions"))
				.addColumn("page_id", t.bigint, (c) => c.notNull().references("tbtop_pages.id").onDelete("cascade"))
				.addColumn("user_id", user)
				.addColumn("kind", "varchar(255)", (c) => c.notNull())
				.addColumn("payload", t.json, (c) => c.notNull())
				.addColumn("created_at", t.stamp)
				.execute();
			await s
				.createIndex("tbtop_page_revisions_page_id_created_at_index")
				.on("tbtop_page_revisions")
				.columns(["page_id", "created_at"])
				.execute();

			await stamps(
				s
					.createTable("notifications")
					.addColumn("id", "varchar(36)", (c) => c.primaryKey())
					.addColumn("type", "varchar(255)", (c) => c.notNull())
					.addColumn("notifiable_type", "varchar(255)", (c) => c.notNull())
					.addColumn("notifiable_id", user, (c) => c.notNull())
					.addColumn("data", "text", (c) => c.notNull())
					.addColumn("read_at", t.stamp),
			).execute();
			await s
				.createIndex("notifications_notifiable_type_notifiable_id_index")
				.on("notifications")
				.columns(["notifiable_type", "notifiable_id"])
				.execute();

			await stamps(
				id(s.createTable("tbtop_not_found_hits"))
					.addColumn("locale", "varchar(5)", (c) => c.notNull())
					.addColumn("path", "text", (c) => c.notNull())
					.addColumn("path_hash", "char(64)", (c) => c.notNull())
					.addColumn("hits", t.bigint, (c) => c.notNull().defaultTo(1))
					.addColumn("last_referer", "varchar(2048)")
					.addColumn("last_user_agent", "varchar(512)")
					.addColumn("first_seen_at", t.stamp, (c) => c.notNull())
					.addColumn("last_seen_at", t.stamp, (c) => c.notNull())
					.addColumn("is_ignored", t.bool, (c) => c.notNull().defaultTo(bool(false)))
					.addUniqueConstraint("tbtop_not_found_hits_locale_path_hash_unique", ["locale", "path_hash"]),
			).execute();

			await stamps(
				id(s.createTable("activity_log"))
					.addColumn("log_name", "varchar(255)")
					.addColumn("description", "text", (c) => c.notNull())
					.addColumn("subject_type", "varchar(255)")
					.addColumn("subject_id", t.bigint)
					.addColumn("event", "varchar(255)")
					.addColumn("causer_type", "varchar(255)")
					.addColumn("causer_id", t.bigint)
					.addColumn("attribute_changes", t.json)
					.addColumn("properties", t.json),
			).execute();
			await s.createIndex("activity_log_log_name_index").on("activity_log").column("log_name").execute();

			await stamps(
				id(s.createTable("tbtop_notification_log"))
					.addColumn("type", "varchar(255)", (c) => c.notNull())
					.addColumn("channel", "varchar(255)", (c) => c.notNull())
					.addColumn("audience", "varchar(255)", (c) => c.notNull())
					.addColumn("recipient", "varchar(255)", (c) => c.notNull())
					.addColumn("locale", "varchar(255)", (c) => c.notNull())
					.addColumn("status", "varchar(255)", (c) => c.notNull())
					.addColumn("error", "text")
					.addColumn("sent_at", t.stamp),
			).execute();

			await stamps(
				id(s.createTable("tbtop_seo_audit_snapshots"))
					.addColumn("captured_on", "date", (c) => c.notNull().unique())
					.addColumn("pages_scanned", "integer", (c) => c.notNull())
					.addColumn("pages_with_issues", "integer", (c) => c.notNull())
					.addColumn("fail_count", "integer", (c) => c.notNull())
					.addColumn("warn_count", "integer", (c) => c.notNull())
					.addColumn("issue_counts", t.json, (c) => c.notNull()),
			).execute();

			await stamps(
				id(s.createTable("tbtop_notification_templates"))
					.addColumn("type", "varchar(255)", (c) => c.notNull())
					.addColumn("format", "varchar(255)", (c) => c.notNull())
					.addColumn("locale", "varchar(255)", (c) => c.notNull())
					.addColumn("subject", "varchar(255)", (c) => c.notNull().defaultTo(""))
					.addColumn("body", "text", (c) => c.notNull())
					.addUniqueConstraint("tbtop_notification_templates_type_format_locale_unique", [
						"type",
						"format",
						"locale",
					]),
			).execute();

			await stamps(
				id(s.createTable("tbtop_shared_blocks"))
					.addColumn("name", "varchar(255)", (c) => c.notNull())
					.addColumn("kind", "varchar(255)", (c) => c.notNull())
					.addColumn("data", t.json, (c) => c.notNull())
					.addColumn("lock_version", "integer", (c) => c.notNull().defaultTo(0)),
			).execute();
			await s.createIndex("tbtop_shared_blocks_kind_index").on("tbtop_shared_blocks").column("kind").execute();

			await id(s.createTable("tbtop_page_refs"))
				.addColumn("page_id", t.bigint, (c) => c.notNull().references("tbtop_pages.id").onDelete("cascade"))
				.addColumn("field", "varchar(255)", (c) => c.notNull())
				.addColumn("target_id", t.bigint, (c) => c.notNull())
				.addColumn("position", "integer", (c) => c.notNull())
				.execute();
			await s
				.createIndex("tbtop_page_refs_target_id_field_index")
				.on("tbtop_page_refs")
				.columns(["target_id", "field"])
				.execute();
			await s.createIndex("tbtop_page_refs_page_id_index").on("tbtop_page_refs").column("page_id").execute();
		},
		async down(db: Kysely<unknown>) {
			for (const table of TABLES.toReversed()) {
				await db.schema.dropTable(table).ifExists().execute();
			}
		},
	};
}

const TABLES = [
	"tbtop_languages",
	"tbtop_pages",
	"tbtop_page_slugs",
	"tbtop_user_profiles",
	"tbtop_redirects",
	"tbtop_settings",
	"tbtop_form_submissions",
	"tbtop_form_submission_comments",
	"tbtop_layouts",
	"tbtop_page_revisions",
	"notifications",
	"tbtop_not_found_hits",
	"activity_log",
	"tbtop_notification_log",
	"tbtop_seo_audit_snapshots",
	"tbtop_notification_templates",
	"tbtop_shared_blocks",
	"tbtop_page_refs",
];

/** A Kysely MigrationProvider: merge it into the host's own migrations, or run `migrateCms`. */
export function cmsMigrations(options: MigrationOptions): MigrationProvider {
	return {
		getMigrations: async () => ({ "2026_10_22_000000_tbtop_cms_baseline": baselineMigration(options) }),
	};
}

/**
 * Runs the package migrations to the latest, then, like `cms:install`, gives
 * a database with no live language its default (`defaultLanguage`, a catalog
 * locale, default `en`; false skips it). Throws on the first failure.
 */
export async function migrateCms(db: Kysely<unknown>, options: MigrationOptions & { defaultLanguage?: string | false }): Promise<void> {
	const migrator = new Migrator({
		db,
		provider: cmsMigrations(options),
		migrationTableName: "tbtop_cms_migrations",
		migrationLockTableName: "tbtop_cms_migrations_lock",
	});
	const { error } = await migrator.migrateToLatest();
	if (error !== undefined) {
		throw error;
	}
	if (options.defaultLanguage !== false) {
		await ensureDefaultLanguage(db as Kysely<CmsDatabase>, options.defaultLanguage);
	}
}
