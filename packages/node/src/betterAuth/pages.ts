import { type ActionCtx, Column, Cond, definePage, Effects, HttpError, type S } from "@tbtop/admin";
import type { Kysely } from "kysely";
import { gated } from "../admin/abilities";
import { adminPrefix, NAV } from "../admin/support";
import type { Dict } from "../support/values";
import type { AdapterCtx } from "./index";
import type { CmsAuthUser } from "./session";

type UsersDb = Record<string, { id: string; name: string; email: string; role: string | null; banned: number | boolean | null; createdAt: string | Date }>;

const usersDb = (ctx: AdapterCtx, request: Request) => ctx.cms.site(request).config.db as unknown as Kysely<UsersDb>;

const roleOptions = (ctx: AdapterCtx) => Object.keys(ctx.roles).map((role) => ({ value: role, label: role[0]?.toUpperCase() + role.slice(1) }));

const me = (action: ActionCtx): CmsAuthUser | null => action.user as CmsAuthUser | null;

/** Runs a Better Auth admin call, turning its refusal (APIError) into an error notice. */
async function refused(call: () => Promise<unknown>): Promise<Effects | null> {
	try {
		await call();
		return null;
	} catch (error) {
		const body = (error as { body?: { message?: unknown } }).body;
		if (typeof body?.message === "string") {
			return Effects.make().notify(body.message, "error");
		}
		throw error;
	}
}

/** Users (UsersPage): Better Auth's users with their role; add, edit, ban, delete. */
export function usersIndex(ctx: AdapterCtx) {
	const prefix = adminPrefix(ctx.cms);
	const userAction = (s: S, name: string, label: string, run: (body: { userId: string }, headers: Headers) => Promise<unknown>, done: string) =>
		s
			.action(name)
			.label(label)
			.handle(async (action) => {
				const body = { userId: String(action.key()) };
				return (await refused(() => run(body, action.request.headers))) ?? Effects.make().notify(done).refreshTable("users");
			}, ["row"]);
	return gated(
		ctx.cms,
		"tbtop-cms.users",
		definePage({
			path: "users",
			title: "Users",
			subtitle: "Who signs in to the admin, and with which role.",
			nav: { group: NAV.settings, label: "Users", icon: "users", order: 20 },
			view: (s, { request }) => {
				const usersQuery = () => usersDb(ctx, request).selectFrom(ctx.usersTable);
				return s.stack([
					s.row([s.action("new").label("Add user").icon("plus").color("primary").url(`${prefix}/users/new`)]),
					s
						.table("users")
						.columns([
							Column.make("name").label("Name").emphasized().searchable().sortable(),
							Column.make("email").label("Email").searchable().sortable(),
							Column.make("role").label("Role").badge(Object.fromEntries(roleOptions(ctx).map((role) => [role.value, { label: role.label, color: role.value === "admin" ? "primary" : "gray" }]))),
							Column.make("banned").label("Banned").boolean(),
							Column.make("createdAt").label("Created").datetime().sortable(),
						])
						.defaultSort("createdAt", "asc")
						.emptyState("No users yet", "Add the first admin user.", "users")
						.recordUrl((row) => `${prefix}/users/${String(row.id)}/edit`)
						.rowActions([
							userAction(s, "ban", "Ban", (body, headers) => ctx.auth.api.banUser({ body, headers }), "User banned and signed out")
								.color("danger")
								.confirm("Ban user?", "They are signed out everywhere and cannot sign in until unbanned.")
								.hiddenIf(Cond.eq("banned", true)),
							userAction(s, "unban", "Unban", (body, headers) => ctx.auth.api.unbanUser({ body, headers }), "User unbanned").hiddenIf(Cond.any(Cond.eq("banned", false), Cond.empty("banned"))),
							userAction(s, "delete", "Delete", (body, headers) => ctx.auth.api.removeUser({ body, headers }), "User deleted")
								.color("danger")
								.confirm("Delete user?", "Their account and sessions are removed. This cannot be undone."),
						])
						.query(usersQuery),
				]);
			},
		}),
	);
}

function userFields(s: S, ctx: AdapterCtx, email: string | null) {
	const isNew = email === null;
	return [
		s.section({ title: "User", columns: 2 }, [
			s.text("name").label("Name").required().rules("max:255"),
			email === null ? s.text("email").label("Email").required().rules("email|max:255") : s.displayValue(email),
			s.select("role").label("Role").required().options(roleOptions(ctx)),
			s
				.password("password")
				.label(isNew ? "Password" : "New password")
				.rules(isNew ? "required|min:8|max:128" : "nullable|min:8|max:128")
				.helperText(isNew ? "At least 8 characters." : "Leave empty to keep the current one."),
		]),
	];
}

/** Add user: Better Auth's admin createUser, with an email and password account. */
export function userNew(ctx: AdapterCtx) {
	const prefix = adminPrefix(ctx.cms);
	return gated(
		ctx.cms,
		"tbtop-cms.users",
		definePage({
			path: "users/new",
			title: "Add user",
			view: (s) =>
				s
					.form("user", [
						...userFields(s, ctx, null),
						s.actionsRow([s.action("save").label("Add").color("primary").submit(), s.action("cancel").label("Cancel").url(`${prefix}/users`)]),
					])
					.record({ role: Object.hasOwn(ctx.roles, "editor") ? "editor" : (Object.keys(ctx.roles)[0] ?? "") })
					.onSubmit(async (submitted) => {
						const form = submitted.form as Dict;
						const body = { name: String(form.name ?? ""), email: String(form.email ?? ""), password: String(form.password ?? ""), role: String(form.role ?? "") as never };
						return (await refused(() => ctx.auth.api.createUser({ body, headers: submitted.request.headers }))) ?? `${prefix}/users`;
					}),
		}),
	);
}

/** Edit user: name, role (not one's own: that would lock the admin out) and an optional new password. */
export function userEdit(ctx: AdapterCtx) {
	const prefix = adminPrefix(ctx.cms);
	return gated(
		ctx.cms,
		"tbtop-cms.users",
		definePage({
			path: "users/$user/edit",
			title: "Edit user",
			view: async (s, { params, request }) => {
				const row = await usersDb(ctx, request).selectFrom(ctx.usersTable).selectAll().where("id", "=", params.user ?? "").executeTakeFirst();
				if (row === undefined) {
					throw new HttpError(404, "User not found.");
				}
				return s
					.form("user", [
						...userFields(s, ctx, row.email),
						s.actionsRow([s.action("save").label("Save").color("primary").submit(), s.action("cancel").label("Back").url(`${prefix}/users`)]),
					])
					.record({ name: row.name, email: row.email, role: row.role ?? "" })
					.onSubmit(async (submitted) => {
						const form = submitted.form as Dict;
						const headers = submitted.request.headers;
						const userId = row.id;
						const role = String(form.role ?? "");
						if (role !== (row.role ?? "") && me(submitted)?.id === userId) {
							return Effects.make().notify("You cannot change your own role.", "error");
						}
						const failed =
							(await refused(() => ctx.auth.api.adminUpdateUser({ body: { userId, data: { name: String(form.name ?? "") } }, headers }))) ??
							(role === (row.role ?? "") ? null : await refused(() => ctx.auth.api.setRole({ body: { userId, role: role as never }, headers }))) ??
							(typeof form.password === "string" && form.password !== "" ? await setPassword(ctx, submitted, userId, form.password) : null);
						return failed ?? Effects.make().notify("Saved").redirect(`${prefix}/users`);
					});
			},
		}),
	);
}

/**
 * A new password from the Users page signs the user's sessions out, as
 * AuthenticateSession does in PHP; one's own keeps the current session.
 */
async function setPassword(ctx: AdapterCtx, action: ActionCtx, userId: string, password: string): Promise<Effects | null> {
	const headers = action.request.headers;
	const failed = await refused(() => ctx.auth.api.setUserPassword({ body: { userId, newPassword: password }, headers }));
	if (failed !== null) {
		return failed;
	}
	// The password is set by now: a refused revoke (a host role without session:revoke) must not read as an unsaved form.
	const revokeFailed = await refused(() =>
		me(action)?.id === userId ? ctx.auth.api.revokeOtherSessions({ headers }) : ctx.auth.api.revokeUserSessions({ body: { userId }, headers }),
	);
	return revokeFailed === null ? null : Effects.make().notify("Password changed, but the user's other sessions were not signed out.", "error");
}
