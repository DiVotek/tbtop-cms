import { rowProblem } from "./blocks/resolver";
import { pageFromRow } from "./content/page";
import { chatIds, mailRecipients } from "./notifications/staff";
import type { Site } from "./site";
import { isDict } from "./support/values";

export type HealthStatus = "ok" | "warning" | "error";

export type HealthResult = { id: string; status: HealthStatus; label: string; message: string };

/** The setting `cms.tasks.scheduled()` stamps on every run; not a `site.*`/`seo.*` key, so never on the frontend. */
export const SCHEDULER_HEARTBEAT = "system.scheduler.heartbeat";
const STALE_AFTER_MINUTES = 5;

type Check = { id: string; label: string; run(site: Site): Promise<[HealthStatus, string]> };

const ok = (message: string): [HealthStatus, string] => ["ok", message];
const warn = (message: string): [HealthStatus, string] => ["warning", message];

/** AppUrlCheck: links in mail and cron output need `url`; it must be http(s) with a host. */
const siteUrl: Check = {
	id: "site_url",
	label: "Site URL",
	run: async (site) => {
		const url = site.config.url;
		if (url === undefined) {
			return warn("`url` is not set: pages take the request's origin, and notifications sent from a cron link to http://localhost.");
		}
		let parsed: URL;
		try {
			parsed = new URL(url);
		} catch {
			return ["error", `\`url\` (${url}) is not an absolute URL.`];
		}
		if (!["http:", "https:"].includes(parsed.protocol) || parsed.hostname === "") {
			return ["error", `\`url\` (${url}) needs an http(s) scheme and a host.`];
		}
		if (parsed.protocol === "http:" || parsed.hostname === "localhost" || parsed.hostname === "127.0.0.1") {
			return warn(`\`url\` is ${url}: canonical links and the sitemap point there.`);
		}
		return ok(url);
	},
};

/** DefaultOwnerAndLanguageCheck: without a default language every URL is a 404. */
const defaultLanguage: Check = {
	id: "default_language",
	label: "Default language",
	run: async (site) => {
		const locale = await site.languages.defaultLocale();
		return locale === "" ? ["error", "No default language: run migrateCms or add one in the admin."] : ok(locale);
	},
};

/** UnregisteredBlockRowsCheck + BrokenSharedReferencesCheck: stored rows the site skips. */
const blockRows: Check = {
	id: "block_rows",
	label: "Block rows",
	run: async (site) => {
		const problems: string[] = [];
		const count = async (where: string, content: unknown) => {
			let n = 0;
			for (const row of Array.isArray(content) ? content : []) {
				if (isDict(row) && typeof row.kind === "string" && (await rowProblem(site, row, row.kind)) !== null) {
					n++;
				}
			}
			if (n > 0) {
				problems.push(`${where}: ${n}`);
			}
		};
		for (const row of await site.pages.query().selectAll("tbtop_pages").orderBy("id").execute()) {
			await count(`page #${row.id}`, pageFromRow(row).content);
		}
		for (const [slot, content] of Object.entries(await site.layouts.content())) {
			await count(`${slot} layout`, content);
		}
		return problems.length === 0 ? ok("Every stored block renders.") : warn(`Skipped blocks (unregistered kind or broken shared block): ${problems.join("; ")}.`);
	},
};

/** PageTypePairsCheck: a page whose type is no longer registered cannot render. */
const pageTypes: Check = {
	id: "page_types",
	label: "Page types",
	run: async (site) => {
		const rows = await site.pages.query().select(["tbtop_pages.id", "tbtop_pages.type", "tbtop_pages.subtype"]).execute();
		const bad = rows.filter((row) => !site.pageTypes.has(row.type) || (row.subtype !== null && row.subtype !== "" && !site.pageTypes.has(row.subtype)));
		return bad.length === 0 ? ok("Every page's type is registered.") : warn(`Pages with an unregistered type: ${bad.map((row) => `#${row.id} (${row.subtype || row.type})`).join(", ")}.`);
	},
};

/** NotificationsConfiguredCheck: an enabled channel with nowhere to send. */
const notifications: Check = {
	id: "notifications",
	label: "Notifications",
	run: async (site) => {
		const enabled = await site.notifications.enabledChannels("form.submission");
		const problems: string[] = [];
		if (enabled.includes("mail") && mailRecipients(await site.settings.get("notifications.mail.recipients")).length === 0) {
			problems.push("mail is on but has no recipients");
		}
		if (enabled.includes("telegram") && chatIds(await site.settings.get("notifications.telegram.chat_ids")).length === 0) {
			problems.push("Telegram is on but has no chat IDs");
		}
		return problems.length === 0 ? ok(enabled.length === 0 ? "No channel is on for form submissions." : `On: ${enabled.join(", ")}.`) : warn(`Form submissions: ${problems.join("; ")}.`);
	},
};

/** SchedulerHeartbeatCheck: with the outbox, nothing is delivered unless `cms.tasks.scheduled()` runs. */
const scheduler: Check = {
	id: "scheduler",
	label: "Scheduler",
	run: async (site) => {
		const raw = await site.settings.get(SCHEDULER_HEARTBEAT);
		const last = typeof raw === "string" ? new Date(raw) : null;
		const outbox = site.config.forms?.delivery === "outbox";
		if (last === null || Number.isNaN(last.getTime())) {
			return outbox ? warn("Form delivery is `outbox`, but `cms.tasks.scheduled()` has never run: submissions wait undelivered.") : ok("Not needed: delivery is inline.");
		}
		const minutes = Math.floor((Date.now() - last.getTime()) / 60_000);
		return minutes > STALE_AFTER_MINUTES && outbox ? warn(`\`cms.tasks.scheduled()\` last ran ${minutes} minutes ago; run it every minute.`) : ok(`Last run ${last.toISOString()}.`);
	},
};

/** Secrets the config names but cannot use. */
const secrets: Check = {
	id: "secrets",
	label: "Secrets",
	run: async (site) => {
		const problems: string[] = [];
		if ((site.config.secretSettings ?? []).length > 0 && (site.config.settingsKey ?? "") === "") {
			problems.push("`secretSettings` is set without `settingsKey`, so those settings cannot be saved");
		}
		const preview = site.config.preview?.secret;
		if (preview !== undefined && preview.length < 16) {
			problems.push("`preview.secret` is shorter than 16 characters");
		}
		return problems.length === 0 ? ok("Configured.") : warn(`${problems.join("; ")}.`);
	},
};

const CHECKS: Check[] = [siteUrl, defaultLanguage, blockRows, pageTypes, notifications, scheduler, secrets];

/** HealthCheckRunner: every check; one that throws reports an error instead of stopping the rest. */
export async function runHealthChecks(site: Site): Promise<HealthResult[]> {
	const out: HealthResult[] = [];
	for (const check of CHECKS) {
		try {
			const [status, message] = await check.run(site);
			out.push({ id: check.id, label: check.label, status, message });
		} catch (error) {
			out.push({ id: check.id, label: check.label, status: "error", message: `The check failed: ${String(error)}` });
		}
	}
	return out;
}
