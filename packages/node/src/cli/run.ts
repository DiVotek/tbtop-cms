import type { Kysely } from "kysely";
import type { Cms } from "../cms";
import { type Dialect, migrateCms } from "../db/migrations";

export type CliIo = { out: (line: string) => void; err: (line: string) => void };

const HELP = `Usage: tbtop-cms <command> [--config cms.config.ts]

Commands:
  migrate             Run the CMS migrations (dialect from the Kysely adapter; --dialect to override)
  doctor              Run the health checks; exits 1 on an error
  scheduled           Deliver the outbox, prune logs and snapshot SEO once a day, stamp the heartbeat (run every minute)
  deliver [--limit N] Deliver pending form submissions now
  not-found:prune     Delete 404 log rows past the retention window
  page-refs:rebuild   Rewrite the page references index
  seo:snapshot        Run the SEO audit and write today's snapshot
  audit [--limit N] [--type page] [--id N]
                      List the admin audit trail, newest first
  audit:prune [--days N]
                      Delete audit entries older than N days (default audit.retentionDays, 365)

The config module's default export is the host's Cms (or a function returning one).`;

/**
 * The Kysely dialect behind a db, for the migrations' column types: by the
 * adapter's class name up its prototype chain, since a dialect package may
 * bring its own copy of kysely (instanceof would miss it). D1 is SQLite.
 */
export function dialectOf(db: Kysely<unknown>): Dialect {
	for (let proto = Object.getPrototypeOf(db.getExecutor().adapter); proto !== null; proto = Object.getPrototypeOf(proto)) {
		const name = String(proto.constructor?.name ?? "");
		if (/postgres/i.test(name)) {
			return "postgres";
		}
		if (/mysql/i.test(name)) {
			return "mysql";
		}
		if (/sqlite/i.test(name)) {
			return "sqlite";
		}
	}
	throw new Error("Unknown Kysely dialect: pass --dialect sqlite|postgres|mysql.");
}

function flag(args: string[], name: string): string | undefined {
	const index = args.indexOf(`--${name}`);
	return index === -1 ? undefined : args[index + 1];
}

function positive(raw: string | undefined): number | undefined {
	const value = Number(raw);
	return raw !== undefined && Number.isInteger(value) && value > 0 ? value : undefined;
}

const MARK = { ok: "[OK]", warning: "[WARN]", error: "[ERROR]" } as const;

/** One CLI run over a loaded Cms; the exit code. */
export async function runCommand(cms: Cms, args: string[], io: CliIo): Promise<number> {
	const [command] = args;
	switch (command) {
		case "migrate": {
			const db = cms.config.db as Kysely<unknown>;
			const dialect = (flag(args, "dialect") as Dialect | undefined) ?? dialectOf(db);
			await migrateCms(db, { dialect });
			io.out(`Migrated (${dialect}).`);
			return 0;
		}
		case "doctor": {
			const results = await cms.tasks.health();
			for (const result of results) {
				io.out(`${MARK[result.status]} ${result.label}: ${result.message}`);
			}
			const count = (status: string) => results.filter((result) => result.status === status).length;
			io.out(`\n${count("ok")} ok, ${count("warning")} warnings, ${count("error")} errors.`);
			return count("error") > 0 ? 1 : 0;
		}
		case "scheduled": {
			const { delivered, pruned, auditPruned } = await cms.tasks.scheduled();
			io.out(`Delivered ${delivered} submissions${pruned === null ? "" : `; pruned ${pruned} 404 log rows and ${auditPruned} audit entries`}.`);
			return 0;
		}
		case "deliver": {
			const limit = Number(flag(args, "limit") ?? 50);
			io.out(`Delivered ${await cms.tasks.deliver({ limit: Number.isInteger(limit) && limit > 0 ? limit : 50 })} submissions.`);
			return 0;
		}
		case "not-found:prune":
			io.out(`Deleted ${await cms.tasks.pruneNotFound()} 404 log rows.`);
			return 0;
		case "page-refs:rebuild":
			io.out(`Wrote ${await cms.tasks.rebuildPageRefs()} page reference rows.`);
			return 0;
		case "audit": {
			const id = positive(flag(args, "id"));
			const type = flag(args, "type");
			const entries = await cms.tasks.audit({
				limit: positive(flag(args, "limit")) ?? 50,
				...(type === undefined ? {} : { subjectType: type }),
				...(id === undefined ? {} : { subjectId: id }),
			});
			for (const entry of entries) {
				const fields = entry.changedFields.length === 0 ? "" : ` (${entry.changedFields.join(", ")})`;
				io.out(`${entry.createdAt ?? "-"}  user ${entry.causerId ?? "-"}  ${entry.event} ${entry.subjectType ?? "?"} #${entry.subjectId ?? "?"} ${entry.label}${fields}`);
			}
			io.out(entries.length === 0 ? "No audit entries." : `\n${entries.length} entries.`);
			return 0;
		}
		case "seo:snapshot":
			io.out(`SEO snapshot written: ${await cms.tasks.seoSnapshot()} issues.`);
			return 0;
		case "audit:prune":
			io.out(`Deleted ${await cms.tasks.pruneAudit(positive(flag(args, "days")))} audit entries.`);
			return 0;
		case undefined:
		case "help":
		case "--help":
			io.out(HELP);
			return 0;
		default:
			io.err(`Unknown command "${command}".\n\n${HELP}`);
			return 2;
	}
}
