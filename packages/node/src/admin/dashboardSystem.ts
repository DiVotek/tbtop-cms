import type { S } from "@tbtop/admin";
import { sql } from "kysely";
import cmsPackage from "../../package.json" with { type: "json" };
import type { Cms } from "../cms";

const POLL_SECONDS = 10;
const HISTORY = 20;
const NA = "n/a";

type Sample = { value: number; total: number };
type Metric = { title: string; icon: string; read: () => Promise<Sample | null>; format: (sample: Sample) => string; describe: (sample: Sample) => string };

/** SystemMetricsHistory: the last samples per metric, in this process (PHP keeps them in the cache). */
const history = new Map<string, number[]>();

function remember(key: string, value: number): { series: number[]; previous: number | null } {
	const series = history.get(key) ?? [];
	const previous = series.at(-1) ?? null;
	const next = [...series, value].slice(-HISTORY);
	history.set(key, next);
	return { series: next, previous };
}

/**
 * A module the runtime may not have: `node:os` on Workers, say. The
 * specifier is a variable so a bundler leaves it alone, and a Workers build
 * never needs nodejs_compat for the dashboard.
 */
async function optional<T>(specifier: string, json = false): Promise<T | null> {
	try {
		return (await (json ? import(/* @vite-ignore */ specifier, { with: { type: "json" } }) : import(/* @vite-ignore */ specifier))) as T;
	} catch {
		return null;
	}
}

const os = () => optional<typeof import("node:os")>("node:os");

const METRICS: Record<string, Metric> = {
	cpu: {
		title: "CPU load",
		icon: "cpu",
		read: async () => {
			const host = await os();
			const load = host?.loadavg()[0];
			const cores = Math.max(1, host?.cpus().length ?? 1);
			return load === undefined ? null : { value: Math.min(100, (load / cores) * 100), total: 100 };
		},
		format: (sample) => `${Math.round(sample.value)} %`,
		describe: () => "1 minute average",
	},
	memory: {
		title: "Memory",
		icon: "memory-stick",
		read: async () => {
			const host = await os();
			if (host === null) {
				return null;
			}
			const mb = 1024 * 1024;
			return { value: (host.totalmem() - host.freemem()) / mb, total: host.totalmem() / mb };
		},
		format: (sample) => `${Math.round(sample.value)} MB / ${Math.round(sample.total)} MB`,
		describe: (sample) => `${sample.total > 0 ? Math.round((sample.value / sample.total) * 100) : 0}% used`,
	},
	disk: {
		title: "Disk",
		icon: "hard-drive",
		read: async () => {
			try {
				const fs = await optional<typeof import("node:fs/promises")>("node:fs/promises");
				if (fs === null) {
					return null;
				}
				const stats = await fs.statfs(".");
				const gb = 1024 * 1024 * 1024;
				return { value: ((stats.blocks - stats.bfree) * stats.bsize) / gb, total: (stats.blocks * stats.bsize) / gb };
			} catch {
				return null;
			}
		},
		format: (sample) => `${sample.value.toFixed(1)} / ${sample.total.toFixed(1)} GB`,
		describe: () => "used space",
	},
};

/**
 * SystemMetrics: CPU, memory and disk of the machine serving the admin,
 * polled every 10 seconds. A runtime without `node:os` (Workers) shows n/a.
 */
export async function systemMetrics(s: S) {
	return Promise.all(
		Object.entries(METRICS).map(async ([key, metric]) => {
			const sample = await metric.read();
			const stat = s.stat(metric.title).icon(metric.icon);
			if (sample === null) {
				return stat.value(NA).description("Not available in this runtime");
			}
			const { series, previous } = remember(key, sample.value);
			return stat
				.value(async () => {
					const fresh = (await metric.read()) ?? sample;
					remember(key, fresh.value);
					return metric.format(fresh);
				})
				.description(metric.describe(sample))
				.trend(previous !== null && sample.value < previous ? "down" : "up")
				.sparkline(series, "bottom")
				.sparklineColor("success")
				.poll(POLL_SECONDS);
		}),
	);
}

/** SystemEnvironment: the versions and the database behind the CMS. */
export function environmentCards(s: S, cms: Cms) {
	const item = (title: string, meta: string) => ({ title, meta, color: "muted" });
	return [
		s.section({ title: "Versions", variant: "card" }, [
			s.list("dashboardSystemVersions").items(async () => [
				item("Runtime", runtime()),
				item("@tbtop/admin version", await adminVersion()),
				item("@tbtop/cms version", cmsPackage.version),
			]),
		]),
		s.section({ title: "Infrastructure", variant: "card" }, [
			s.list("dashboardSystemInfrastructure").items(async () => [item("Database driver", driver(cms)), item("Database size", await databaseSize(cms))]),
		]),
	];
}

function runtime(): string {
	const bun = (globalThis as { Bun?: { version: string } }).Bun;
	if (bun !== undefined) {
		return `Bun ${bun.version}`;
	}
	const node = (globalThis as { process?: { versions?: { node?: string } } }).process?.versions?.node;
	if (node !== undefined) {
		return `Node.js ${node}`;
	}
	return (globalThis as { navigator?: { userAgent?: string } }).navigator?.userAgent ?? NA;
}

async function adminVersion(): Promise<string> {
	const pkg = await optional<{ default?: { version?: string }; version?: string }>("@tbtop/admin/package.json", true);
	return pkg?.default?.version ?? pkg?.version ?? NA;
}

/** The Kysely adapter's dialect: sqlite, postgres or mysql. */
function driver(cms: Cms): string {
	const name = cms.config.db.getExecutor().adapter.constructor.name.replace(/Adapter$/, "").toLowerCase();
	return name === "" ? NA : name;
}

async function databaseSize(cms: Cms): Promise<string> {
	const db = cms.config.db;
	try {
		const kind = driver(cms);
		if (kind === "postgres") {
			const row = await sql<{ size: string | number }>`select pg_database_size(current_database()) as size`.execute(db);
			return fileSize(Number(row.rows[0]?.size ?? 0));
		}
		if (kind === "mysql") {
			const row = await sql<{ size: string | number | null }>`select sum(data_length + index_length) as size from information_schema.tables where table_schema = database()`.execute(db);
			return fileSize(Number(row.rows[0]?.size ?? 0));
		}
		if (kind === "sqlite") {
			const pages = await sql<{ page_count: number }>`pragma page_count`.execute(db);
			const size = await sql<{ page_size: number }>`pragma page_size`.execute(db);
			return fileSize(Number(pages.rows[0]?.page_count ?? 0) * Number(size.rows[0]?.page_size ?? 0));
		}
	} catch {
		// D1 and restricted users refuse these: the size is then unknown, not an error.
	}
	return NA;
}

function fileSize(bytes: number): string {
	const units = ["B", "KB", "MB", "GB", "TB"];
	let value = bytes;
	let unit = 0;
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024;
		unit++;
	}
	return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
}
