import { definePage, Effects, type S } from "@tbtop/admin";
import { CMS_BRAND } from "../admin/panel";
import { adminPrefix } from "../admin/support";
import type { Cms } from "../cms";
import { clientKey } from "../forms/throttle";
import type { Dict } from "../support/values";
import type { AdapterCtx } from "./index";

/**
 * Sign in (LoginPage): email and password through Better Auth, the only page a
 * guest reaches. Chrome-less center layout with the PHP login's branding: the
 * product mark, a card headed by the site name, a link back to the site and
 * the footer line.
 */
export function loginPage(ctx: AdapterCtx) {
	const prefix = adminPrefix(ctx.cms);
	return definePage({
		path: "login",
		title: "Sign in",
		layout: "center",
		public: true,
		mcp: false,
		view: async (s, { request }) => {
			const form = s
				.form("login", [
					s.text("email").label("Email").required().rules("email|max:255"),
					s.password("password").label("Password").required(),
					s.checkbox("remember").label("Remember me"),
					// A stack, not actionsRow: its stretched child makes the button full width.
					s.stack([s.action("signIn").label("Sign in").color("primary").submit()]),
				])
				.record({ email: "", password: "", remember: false })
				.onSubmit(async (submitted) => {
					const form = submitted.form as Dict;
					const email = String(form.email ?? "");
					const key = `${email.toLowerCase()}|${await clientKey(submitted.request, ctx.cms.config.forms?.clientIp)}`;
					const retryAfter = ctx.throttle?.hit(key) ?? null;
					if (retryAfter !== null) {
						return Effects.make().notify(`Too many sign-in attempts. Try again in ${retryAfter} seconds.`, "error");
					}
					if (await ctx.signIn(submitted.request, email, String(form.password ?? ""), form.remember === true)) {
						ctx.throttle?.clear(key);
						return `${prefix}/dashboard`;
					}
					return Effects.make().notify("These credentials do not match an admin account.", "error");
				});
			return brandedCard(s, await heading(ctx.cms, request), form);
		},
	});
}

/** The login screen around its form; the classes are the PHP LoginPage's. */
function brandedCard(s: S, title: string, form: unknown) {
	// Fixed width, not w-full: the center wrapper shrink-wraps its content, so
	// a percentage width would widen with a long validation message.
	const card = s.section({ variant: "card", class: "w-96 max-w-full mx-auto shadow-md" }, [
		s.stack(
			[s.stack([s.displayText(title).variant("heading"), s.displayText("Sign in to the control panel").variant("muted")], { class: "text-center gap-1" }), form],
			{ class: "gap-6" },
		),
	]);
	return s.stack(
		[
			s.displayHtml(LOGO_SVG),
			card,
			s.displayHtml('<a href="/" class="block text-center text-sm text-muted-foreground hover:text-foreground">&larr; Go to site</a>'),
			// Pinned to the viewport bottom; the outer pb-14 keeps it off the site link.
			s.stack([s.displayText(`${CMS_BRAND} © ${new Date().getFullYear()}`).variant("muted")], { class: "fixed bottom-0 inset-x-0 py-4 text-center bg-background" }),
		],
		{ class: "gap-6 pb-14" },
	);
}

/** The site's name from Site settings (which project this login opens), else the product brand. */
async function heading(cms: Cms, request: Request): Promise<string> {
	const name = String((await cms.site(request).settings.get("site.name")) ?? "").trim();
	return name !== "" ? name : CMS_BRAND;
}

/** The product mark: tbtop.dev's hero tiles (t·b·t·o·p) over the product name, which follows the theme (currentColor). */
const LOGO_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 212 68" class="mx-auto h-16 w-auto text-foreground" role="img" aria-label="TableTop CMS" font-family="ui-sans-serif, system-ui, sans-serif"><defs><linearGradient id="tt0" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8b5cf6"/><stop offset="1" stop-color="#6d28d9"/></linearGradient><linearGradient id="tt1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f472b6"/><stop offset="1" stop-color="#db2777"/></linearGradient><linearGradient id="tt2" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f5b83d"/><stop offset="1" stop-color="#d97706"/></linearGradient><linearGradient id="tt3" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#a3e635"/><stop offset="1" stop-color="#65a30d"/></linearGradient><linearGradient id="tt4" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#0891b2"/></linearGradient><linearGradient id="ttgloss" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".42"/><stop offset=".4" stop-color="#fff" stop-opacity="0"/></linearGradient></defs><g><rect x="0" y="0" width="36" height="36" rx="8" fill="url(#tt0)"/><rect x="0" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="18" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">t</text><rect x="44" y="0" width="36" height="36" rx="8" fill="url(#tt1)"/><rect x="44" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="62" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">b</text><rect x="88" y="0" width="36" height="36" rx="8" fill="url(#tt2)"/><rect x="88" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="106" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">t</text><rect x="132" y="0" width="36" height="36" rx="8" fill="url(#tt3)"/><rect x="132" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="150" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">o</text><rect x="176" y="0" width="36" height="36" rx="8" fill="url(#tt4)"/><rect x="176" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="194" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">p</text></g><text x="0" y="64" textLength="212" lengthAdjust="spacing" font-size="24" font-weight="700" fill="currentColor">TableTop CMS</text></svg>`;
