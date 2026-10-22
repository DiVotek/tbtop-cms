import type { ColumnType, Generated } from "kysely";

/**
 * The tables of the PHP package, 1:1: same names (prefix `tbtop_`), same
 * columns, same JSON shapes, so a Node host can open a Laravel database.
 * JSON columns hold text; dates are `Y-m-d H:i:s` strings in UTC.
 */
type Json = string;
type Timestamp = string;
type Bool = ColumnType<number | boolean, number | boolean, number | boolean>;
type Stamps = {
	created_at: Timestamp | null;
	updated_at: Timestamp | null;
};
/** The host's user key: an integer id or a uuid/ulid string. */
type UserKey = string | number;

export type LanguagesTable = Stamps & {
	id: Generated<number>;
	name: string;
	locale: string;
	og_locale: string | null;
	is_default: Bool;
	is_admin_active: Bool;
	is_frontend_active: Bool;
	sort_order: Generated<number>;
	deleted_at: Timestamp | null;
};

export type PagesTable = Stamps & {
	id: Generated<number>;
	type: string;
	subtype: string | null;
	source_id: string | null;
	parent_id: number | null;
	status: string;
	published_at: Timestamp | null;
	unpublish_at: Timestamp | null;
	content: Json | null;
	fields: Json | null;
	starter_blocks: Json | null;
	name: Json | null;
	title: Json | null;
	heading: Json | null;
	summary: Json | null;
	body: Json | null;
	image: string | null;
	seo: Json | null;
	is_indexable: Bool;
	is_system: Bool;
	error_class: string | null;
	sort_order: Generated<number>;
	lock_version: Generated<number>;
	created_by: UserKey | null;
	updated_by: UserKey | null;
	deleted_at: Timestamp | null;
};

export type PageSlugsTable = Stamps & {
	id: Generated<number>;
	page_id: number;
	locale: string;
	slug: string;
	path: string;
};

export type RedirectsTable = Stamps & {
	id: Generated<number>;
	locale: string | null;
	from_path: string;
	to_path: string;
	status_code: number;
	is_auto: Bool;
	created_by: UserKey | null;
	updated_by: UserKey | null;
};

export type SettingsTable = Stamps & {
	id: Generated<number>;
	key: string;
	value: string | null;
};

export type LayoutsTable = Stamps & {
	id: Generated<number>;
	slot: string;
	content: Json | null;
};

export type SharedBlocksTable = Stamps & {
	id: Generated<number>;
	name: string;
	kind: string;
	data: Json;
	lock_version: Generated<number>;
};

export type PageRefsTable = {
	id: Generated<number>;
	page_id: number;
	field: string;
	target_id: number;
	position: number;
};

export type PageRevisionsTable = {
	id: Generated<number>;
	page_id: number;
	user_id: UserKey | null;
	kind: string;
	payload: Json;
	created_at: Timestamp | null;
};

export type UserProfilesTable = Stamps & {
	id: Generated<number>;
	user_id: UserKey;
	is_owner: Bool;
	locale: string | null;
	avatar_media_id: string | null;
	display_name: string | null;
};

export type FormSubmissionsTable = Stamps & {
	id: Generated<number>;
	page_id: number | null;
	block_id: string;
	kind: string;
	locale: string;
	payload: Json;
	payload_order: Json | null;
	status: string;
	lead_status: string;
	error: string | null;
	handled_at: Timestamp | null;
};

export type FormSubmissionCommentsTable = Stamps & {
	id: Generated<number>;
	submission_id: number;
	body: string;
};

export type NotFoundHitsTable = Stamps & {
	id: Generated<number>;
	locale: string;
	path: string;
	path_hash: string;
	hits: number;
	last_referer: string | null;
	last_user_agent: string | null;
	first_seen_at: Timestamp;
	last_seen_at: Timestamp;
	is_ignored: Bool;
};

export type NotificationLogTable = Stamps & {
	id: Generated<number>;
	type: string;
	channel: string;
	audience: string;
	recipient: string;
	locale: string;
	status: string;
	error: string | null;
	sent_at: Timestamp | null;
};

export type NotificationTemplatesTable = Stamps & {
	id: Generated<number>;
	type: string;
	format: string;
	locale: string;
	subject: string;
	body: string;
};

export type SeoAuditSnapshotsTable = Stamps & {
	id: Generated<number>;
	captured_on: string;
	pages_scanned: number;
	pages_with_issues: number;
	fail_count: number;
	warn_count: number;
	issue_counts: Json;
};

export type ActivityLogTable = Stamps & {
	id: Generated<number>;
	log_name: string | null;
	description: string;
	subject_type: string | null;
	subject_id: string | number | null;
	event: string | null;
	causer_type: string | null;
	causer_id: string | number | null;
	attribute_changes: Json | null;
	properties: Json | null;
};

export type NotificationsTable = Stamps & {
	id: string;
	type: string;
	notifiable_type: string;
	notifiable_id: string | number;
	data: string;
	read_at: Timestamp | null;
};

export type CmsDatabase = {
	tbtop_languages: LanguagesTable;
	tbtop_pages: PagesTable;
	tbtop_page_slugs: PageSlugsTable;
	tbtop_redirects: RedirectsTable;
	tbtop_settings: SettingsTable;
	tbtop_layouts: LayoutsTable;
	tbtop_shared_blocks: SharedBlocksTable;
	tbtop_page_refs: PageRefsTable;
	tbtop_page_revisions: PageRevisionsTable;
	tbtop_user_profiles: UserProfilesTable;
	tbtop_form_submissions: FormSubmissionsTable;
	tbtop_form_submission_comments: FormSubmissionCommentsTable;
	tbtop_not_found_hits: NotFoundHitsTable;
	tbtop_notification_log: NotificationLogTable;
	tbtop_notification_templates: NotificationTemplatesTable;
	tbtop_seo_audit_snapshots: SeoAuditSnapshotsTable;
	activity_log: ActivityLogTable;
	notifications: NotificationsTable;
};
