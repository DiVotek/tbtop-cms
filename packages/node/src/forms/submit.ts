import { type HostRule, validate, type ValidationResult } from "@tbtop/admin";
import type { Page } from "../content/page";
import { honeypotField } from "../blocks/resolver";
import { validationLang } from "../support/strings";
import type { Site } from "../site";
import { locateForm } from "./locate";
import type { Submission } from "./submissions";
import { notifySubmission } from "./notifier";
import { clientKey, type SubmitThrottle } from "./throttle";

const ROUTE = /^\/_cms\/forms\/([^/]+)\/(\d+)\/([^/]+)\/?$/;

type Outcome = "sent" | "invalid" | "failed";

/** SubmissionReceived: what `forms.onSubmission` gets. `submission` is null with `forms.store: false`. */
export type SubmissionEvent = { submission: Submission | null; payload: Record<string, unknown>; page: Page; blockId: string; locale: string };

/**
 * FormSubmitController: POST /_cms/forms/{locale}/{page}/{block}. A fetch
 * client (Accept or Content-Type JSON) gets JSON: 200 `{ ok }`, 422
 * `{ errors }`, 500 `{ errors: { form } }`. A plain HTML form gets a 303
 * back to the page with `cms_form` and `cms_form_status` in the query.
 * Null for any other URL.
 */
export async function submitForm(
	site: Site,
	request: Request,
	throttle: SubmitThrottle | null = null,
	hostRules: Record<string, HostRule> = {},
): Promise<Response | null> {
	const url = new URL(request.url);
	const match = ROUTE.exec(url.pathname);
	if (request.method !== "POST" || match === null) {
		return null;
	}
	if (crossSite(request, site.origin, site.config.url === undefined)) {
		// The PHP route sits in the `web` group, whose CSRF check answers 419 here.
		return new Response(null, { status: 403 });
	}
	const [, rawLocale = "", rawPage = "", rawBlock = ""] = match;
	const blockId = decodeURIComponent(rawBlock);
	// The `throttle` middleware: every post that reaches the route counts, before the page or form is looked up.
	const retryAfter = throttle?.hit(await clientKey(request, site.config.forms?.clientIp)) ?? null;
	if (retryAfter !== null) {
		return new Response(null, { status: 429, headers: { "Retry-After": String(retryAfter) } });
	}
	const allow = site.config.forms?.allow;
	if (allow !== undefined && !(await allow(request))) {
		return new Response(null, { status: 429 });
	}
	const locale = await submitLocale(site, decodeURIComponent(rawLocale));
	const page = await site.pages.find(Number(rawPage), { live: true });
	const located = page === null ? null : await locateForm(site, page, blockId, locale);
	if (page === null || located === null) {
		return new Response(null, { status: 404 });
	}
	const reply = responder(site, request, blockId, await site.pageUrl(page, locale));
	const input = cleanInput(await readBody(request));
	const honeypot = honeypotField(site);
	if (honeypot !== null && String(input[honeypot] ?? "").trim() !== "") {
		// Look exactly like success, so a bot's script has nothing to learn; nothing is stored or sent.
		console.debug("form.submit.honeypot_tripped", { page: page.id, block: blockId });
		return reply("sent");
	}
	let result: ValidationResult;
	try {
		const rules = Object.fromEntries(
			Object.entries(located.submit.rules(located.data, located.ctx)).map(([field, rule]) => [field, Array.isArray(rule) ? rule : rule.split("|")]),
		);
		// The admin's rules: an unknown one throws (fails closed), which is the site's fault, not the visitor's.
		// The visitor's language: Laravel validates in the app locale the page set.
		const { messages, attributes } = validationLang(locale, site.config.strings);
		result = await validate(input, rules, { hostRules, attributes, ...(Object.keys(messages).length > 0 ? { messages } : {}) });
	} catch (error) {
		console.error("form.submit.validation_failed", { page: page.id, block: blockId, error: error instanceof Error ? error.message : String(error) });
		return reply("failed", { form: [await site.t("forms.submit_failed", locale)] });
	}
	if (!result.ok) {
		return reply("invalid", result.errors);
	}
	const payload = result.validated;
	const submission =
		site.config.forms?.store === false
			? null
			: await site.submissions.create({ pageId: page.id, blockId, kind: located.block.kind, locale, payload });
	await received(site, { submission, payload, page, blockId, locale });
	try {
		await located.submit.handle?.(payload, located.data, located.ctx);
		if (submission !== null) {
			await site.submissions.markHandled(submission.id);
		}
	} catch (error) {
		const message = error instanceof Error ? error.message : String(error);
		console.error("form.submit.failed", { page: page.id, block: blockId, error: message });
		if (submission !== null) {
			await site.submissions.fail(submission.id, message);
		}
		return reply("failed", { form: [await site.t("forms.submit_failed", locale)] });
	}
	if (submission !== null && site.config.forms?.delivery !== "outbox") {
		await notifySubmission(site, await site.submissions.findOrFail(submission.id));
	}
	return reply("sent");
}

/** The host's listener runs as PHP's event does, before the block's handler; a throw is logged, never the visitor's error. */
async function received(site: Site, event: SubmissionEvent): Promise<void> {
	try {
		await site.config.forms?.onSubmission?.(event);
	} catch (error) {
		console.error("form.submit.on_submission_failed", { page: event.page.id, block: event.blockId, error: error instanceof Error ? error.message : String(error) });
	}
}

/**
 * The frontend has no session to carry a CSRF token, so a post counts as
 * cross-site when the browser says so: `Sec-Fetch-Site: cross-site`, an
 * Origin (else a Referer) off the site, or an opaque `null` Origin. A client
 * that sends neither header is no browser, so no visitor's to forge. With no
 * configured `url` the origin comes from the request, which behind a
 * TLS-terminating proxy is `http` while the browser says `https`: then only
 * the host is compared.
 */
function crossSite(request: Request, siteOrigin: string, hostOnly: boolean): boolean {
	if (request.headers.get("sec-fetch-site") === "cross-site") {
		return true;
	}
	const source = request.headers.get("origin") ?? request.headers.get("referer");
	if (source === null) {
		return false;
	}
	const key = hostOnly ? hostOf : originOf;
	const own = [siteOrigin, request.url].map(key);
	const from = key(source);
	return from === null || !own.includes(from);
}

const hostOf = (url: string): string | null => {
	const origin = originOf(url);
	return origin === null ? null : new URL(origin).host;
};

const originOf = (url: string): string | null => {
	try {
		const origin = new URL(url).origin;
		return origin === "null" ? null : origin;
	} catch {
		return null;
	}
};

/** SetLanguage for the submit: a frontend-active locale, else the default. */
async function submitLocale(site: Site, locale: string): Promise<string> {
	const active = (await site.languages.frontendActive()).map((row) => row.locale);
	return active.includes(locale) ? locale : site.languages.defaultLocale();
}

/** The fields as strings: a JSON object, or url-encoded / multipart form data (files are dropped). */
async function readBody(request: Request): Promise<Record<string, unknown>> {
	const type = request.headers.get("content-type") ?? "";
	try {
		if (type.includes("application/json")) {
			const body = (await request.json()) as unknown;
			return typeof body === "object" && body !== null && !Array.isArray(body) ? (body as Record<string, unknown>) : {};
		}
		const form = await request.formData();
		const out: Record<string, unknown> = {};
		for (const [key, value] of form.entries()) {
			if (typeof value === "string") {
				out[key] = value;
			}
		}
		return out;
	} catch {
		return {};
	}
}

/** Kept as typed, as Laravel's TrimStrings keeps them. */
const UNTRIMMED = new Set(["password", "password_confirmation", "current_password"]);

/**
 * Laravel's TrimStrings + ConvertEmptyStringsToNull, which run before the
 * PHP controller validates: every string, nested ones too, is trimmed
 * (zero-width spaces included) and a blank one becomes null.
 */
function cleanInput(input: Record<string, unknown>): Record<string, unknown> {
	return Object.fromEntries(Object.entries(input).map(([key, value]) => [key, clean(value, !UNTRIMMED.has(key))]));
}

function clean(value: unknown, trim = true): unknown {
	if (typeof value === "string") {
		const text = trim ? value.replace(/^[\s\uFEFF\u200B\u200E]+|[\s\uFEFF\u200B\u200E]+$/g, "") : value;
		return text === "" ? null : text;
	}
	if (Array.isArray(value)) {
		return value.map((item) => clean(item));
	}
	if (typeof value === "object" && value !== null) {
		return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, clean(item)]));
	}
	return value;
}

function responder(site: Site, request: Request, blockId: string, pageUrl: string | null) {
	const accept = request.headers.get("accept") ?? "";
	const json = accept.includes("application/json") || (request.headers.get("content-type") ?? "").includes("application/json");
	return (outcome: Outcome, errors: Record<string, string[]> = {}): Response => {
		const status = outcome === "sent" ? 200 : outcome === "invalid" ? 422 : 500;
		if (json) {
			return Response.json(outcome === "sent" ? { ok: true } : { ok: false, errors }, { status });
		}
		const back = new URL(sameOrigin(request.headers.get("referer"), site.origin) ?? site.absolute(pageUrl ?? "/"));
		back.searchParams.set("cms_form", blockId);
		back.searchParams.set("cms_form_status", outcome);
		return new Response(null, { status: 303, headers: { Location: back.toString() } });
	};
}

/** The Referer only when it is on the site, so the redirect never leaves it. */
function sameOrigin(referer: string | null, origin: string): string | null {
	if (referer === null) {
		return null;
	}
	try {
		return new URL(referer).origin === new URL(origin).origin ? referer : null;
	} catch {
		return null;
	}
}
