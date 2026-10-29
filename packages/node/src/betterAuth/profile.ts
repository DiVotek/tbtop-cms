import { definePage, Effects } from "@tbtop/admin";
import type { Dict } from "../support/values";
import type { AdapterCtx } from "./index";
import type { CmsAuthUser } from "./session";

/** Better Auth's error body (`{ message }`) as an error notice; a body without one says the change failed. */
async function failure(response: Response): Promise<Effects> {
	const body = (await response.json().catch(() => null)) as { message?: unknown } | null;
	return Effects.make().notify(typeof body?.message === "string" ? body.message : "The change was not saved.", "error");
}

/**
 * Profile (ProfilePage): the signed-in user's own name and password, open to
 * every role that gets into the panel (an editor has no Users page). Email
 * is shown, not edited: it is where a reset would go. A new password needs
 * the current one, signs every other session out (AuthenticateSession) and
 * keeps this one through Better Auth's fresh cookie. Wrong current passwords
 * are throttled per user, as the sign-in is.
 */
export function profilePage(ctx: AdapterCtx) {
	return definePage({
		path: "profile",
		title: "Profile",
		subtitle: "Your name and password.",
		mcp: false,
		view: (s, { request }) => {
			const user = ctx.user(request);
			const personal = s
				.form("personal", [
					s.text("name").label("Name").required().rules("max:255"),
					s.displayText(`Email: ${user?.email ?? ""}`).variant("muted"),
					s.actionsRow([s.action("save").label("Save").color("primary").submit()]),
				])
				.record({ name: user?.name ?? "" })
				.onSubmit(async (submitted) => {
					const response = await ctx.auth.api.updateUser({ body: { name: String((submitted.form as Dict).name ?? "") }, headers: submitted.request.headers, asResponse: true });
					if (!response.ok) {
						return failure(response);
					}
					ctx.carry(submitted.request, response);
					return Effects.make().notify("Saved");
				});
			const password = s
				.form("password", [
					s.password("current_password").label("Current password").required(),
					s.password("password").label("New password").required().rules("min:8|max:128|confirmed").helperText("At least 8 characters."),
					s.password("password_confirmation").label("Confirm new password").required(),
					s.actionsRow([s.action("change").label("Update password").color("primary").submit()]),
				])
				.record({ current_password: "", password: "", password_confirmation: "" })
				.guardUnsaved(false)
				.onSubmit(async (submitted) => {
					const form = submitted.form as Dict;
					const key = `profile|${(submitted.user as CmsAuthUser | null)?.id ?? ""}`;
					const retryAfter = ctx.throttle?.hit(key) ?? null;
					if (retryAfter !== null) {
						return Effects.make().notify(`Too many attempts. Try again in ${retryAfter} seconds.`, "error");
					}
					const response = await ctx.auth.api.changePassword({
						body: { currentPassword: String(form.current_password ?? ""), newPassword: String(form.password ?? ""), revokeOtherSessions: true },
						headers: submitted.request.headers,
						asResponse: true,
					});
					if (!response.ok) {
						return failure(response);
					}
					ctx.throttle?.clear(key);
					ctx.carry(submitted.request, response);
					return Effects.make().notify("Password updated. Other devices are signed out.").resetForm("password");
				});
			return s.stack(
				[
					s.tabs([
						{ label: "Personal info", children: [personal] },
						{ label: "Change password", children: [password] },
					]),
				],
				{ class: "mx-auto w-full max-w-2xl" },
			);
		},
	});
}
