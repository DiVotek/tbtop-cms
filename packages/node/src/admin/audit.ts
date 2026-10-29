import { Column, definePage, HttpError } from "@tbtop/admin";
import type { Cms } from "../cms";
import { type Dict, decodeJson, isDict, parseDate } from "../support/values";
import { adminPrefix, listHeader, NAV, recordCrumbs } from "./support";

const TYPES: Record<string, string> = {
	page: "Page",
	redirect: "Redirect",
	language: "Language",
	layout: "Layout",
	entry: "Collection entry",
	shared_block: "Shared block",
	setting: "Setting",
	submission: "Form submission",
	not_found: "404 entry",
	user: "User",
};

const EVENTS: Record<string, string> = {
	created: "Created",
	updated: "Updated",
	deleted: "Deleted",
	restored: "Restored",
	force_deleted: "Permanently deleted",
	published: "Published",
	unpublished: "Unpublished",
	duplicated: "Duplicated",
	revision_restored: "Revision restored",
	starter_blocks_applied: "Starter blocks applied",
	default_changed: "Default changed",
	status_changed: "Status changed",
	delivery_retried: "Delivery retried",
	comment_added: "Comment added",
	password_changed: "Password changed",
	access_granted: "Access granted",
	access_revoked: "Access revoked",
	profile_updated: "Profile updated",
	ignored: "Ignored",
	unignored: "Unignored",
};

const eventLabel = (event: unknown): string => EVENTS[String(event)] ?? String(event ?? "");
const typeLabel = (type: unknown): string => TYPES[String(type)] ?? String(type ?? "");
const authorLabel = (causer: unknown): string => (causer === null || causer === undefined || causer === "" ? "System" : `User #${String(causer)}`);
const properties = (row: Dict): Dict => {
	const value = decodeJson(row.properties);
	return isDict(value) ? value : {};
};
const options = (labels: Record<string, string>) => Object.entries(labels).map(([value, label]) => ({ value, label }));

/** AuditLogPage: the `tbtop-cms` audit trail, newest first; a row opens the entry. */
export function auditLog(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "audit",
		...listHeader("settings", "Audit log", "Administrative changes across CMS entities."),
		nav: { group: NAV.settings, label: "Audit log", icon: "history", order: 40 },
		view: async (s, { request }) => {
			const db = cms.site(request).config.db;
			return s.stack([
				s
					.table("auditActivities")
					.columns([
						Column.make("created_at").label("Time").datetime().sortable(),
						Column.make("causer_id").label("Author").muted().formatUsing(authorLabel),
						Column.make("event").label("Action").formatUsing(eventLabel),
						Column.make("subject_type").label("Entity type").formatUsing(typeLabel),
						Column.make("properties")
							.label("Entity")
							.emphasized()
							.formatUsing((value) => String(properties({ properties: value }).subject_label ?? "")),
						Column.make("attribute_changes")
							.label("Changed fields")
							.formatUsing((_, row) => {
								const fields = properties(row as Dict).changed_fields;
								return Array.isArray(fields) ? fields.join(", ") : "";
							}),
					])
					.filters([
						s.select("subject_type").label("Entity type").options(options(TYPES)),
						s
							.text("subject_id")
							.label("Entity ID")
							.filterUsing((qb, value) => (/^\d+$/.test(String(value)) ? (qb as ReturnType<typeof query>).where("subject_id", "=", Number(value)) : qb)),
						s.select("event").label("Action").options(options(EVENTS)),
						s.daterange("created_at").label("Period"),
					])
					.filtersFormColumns(2)
					.defaultSort("created_at", "desc")
					.paginate(25)
					.recordUrl((row) => `${prefix}/audit/${String(row.id)}`)
					.emptyState("No audit entries", "Administrative changes will appear here.", "history")
					.query(() => query()),
			]);
			function query() {
				return db.selectFrom("activity_log").where("log_name", "=", "tbtop-cms");
			}
		},
	});
}

/** AuditActivityPage: one entry, its summary and the values before, after and around the change. */
export function auditActivity(cms: Cms) {
	const prefix = adminPrefix(cms);
	const titles = new WeakMap<Request, string>();
	return definePage({
		path: "audit/$activity",
		title: ({ request }) => titles.get(request) ?? "Audit entry",
		breadcrumbs: ({ request }) => recordCrumbs("settings", { label: "Audit log", url: `${prefix}/audit` }, titles.get(request) ?? "Audit entry"),
		view: async (s, { params, request }) => {
			const id = Number(params.activity);
			const row = Number.isInteger(id)
				? await cms.site(request).config.db.selectFrom("activity_log").selectAll().where("log_name", "=", "tbtop-cms").where("id", "=", id).executeTakeFirst()
				: undefined;
			if (row === undefined) {
				throw new HttpError(404, "Audit entry not found.");
			}
			titles.set(request, eventLabel(row.event));
			const props = properties(row as Dict);
			const changes = decodeJson(row.attribute_changes);
			const change = isDict(changes) ? changes : {};
			const time = parseDate(row.created_at);
			const section = (title: string, values: unknown) => {
				const entries = Object.entries(isDict(values) ? values : {});
				return s.section({ title, variant: "card" }, [
					entries.length === 0 ? s.displayText("No values.").variant("muted") : s.displayKeyValue(Object.fromEntries(entries.map(([key, value]) => [key, formatValue(value)]))),
				]);
			};
			return s.stack([
				s.section({ title: "Summary", variant: "card" }, [
					s.displayKeyValue({
						Time: time === null ? "" : time.toISOString().slice(0, 19).replace("T", " "),
						Author: authorLabel(row.causer_id),
						Action: eventLabel(row.event),
						"Entity type": typeLabel(row.subject_type),
						Entity: String(props.subject_label ?? ""),
					}),
				]),
				section("Before", change.old),
				section("After", change.attributes),
				section("Context", props.context),
			]);
		},
	});
}

function formatValue(value: unknown): string {
	if (typeof value === "boolean") {
		return value ? "Yes" : "No";
	}
	if (value === null || value === undefined) {
		return "—";
	}
	return typeof value === "object" ? JSON.stringify(value) : String(value);
}
