import { describe, expect, test } from "bun:test";
import type { Kysely } from "kysely";
import { dialectOf, runCommand } from "../src/cli/run";
import { get, makeCms, testDialect } from "./support";

const en = [{ locale: "en", name: "English", default: true }];
const fixture = { languages: en, pages: [{ key: "home", system: true, name: { en: "Home" } }] };

function capture() {
	const lines: string[] = [];
	return { lines, io: { out: (line: string) => lines.push(line), err: (line: string) => lines.push(`ERR ${line}`) } };
}

describe("tbtop-cms CLI", () => {
	test("doctor reports each check and exits 1 on an error", async () => {
		const { cms } = await makeCms(fixture);
		const run = capture();
		expect(await runCommand(cms, ["doctor"], run.io)).toBe(0);
		expect(run.lines.some((line) => line.startsWith("[OK] Default language: en"))).toBe(true);

		const broken = await makeCms(fixture, { url: "not a url" });
		const failed = capture();
		expect(await runCommand(broken.cms, ["doctor"], failed.io)).toBe(1);
		expect(failed.lines.some((line) => line.startsWith("[ERROR] Site URL"))).toBe(true);
	});

	test("doctor warns about unregistered block rows and an outbox with no scheduler", async () => {
		const { cms, ids } = await makeCms(fixture, { forms: { delivery: "outbox" } });
		await cms.site().pages.update(ids.home as number, { content: [{ id: "x1", kind: "Gone", data: {} }] });
		const results = await cms.tasks.health();
		expect(results.find((result) => result.id === "block_rows")).toMatchObject({ status: "warning" });
		expect(results.find((result) => result.id === "scheduler")).toMatchObject({ status: "warning" });

		await cms.tasks.scheduled();
		expect((await cms.tasks.health()).find((result) => result.id === "scheduler")).toMatchObject({ status: "ok" });
	});

	test("scheduled prunes the 404 log once a day; the other commands answer with counts", async () => {
		const { cms } = await makeCms(fixture);
		expect(await cms.tasks.scheduled()).toEqual({ delivered: 0, pruned: 0, auditPruned: 0 });
		expect(await cms.tasks.scheduled()).toEqual({ delivered: 0, pruned: null, auditPruned: null });

		await cms.loadPage(get("/gone"));
		const run = capture();
		expect(await runCommand(cms, ["page-refs:rebuild"], run.io)).toBe(0);
		expect(await runCommand(cms, ["not-found:prune"], run.io)).toBe(0);
		expect(await runCommand(cms, ["nope"], run.io)).toBe(2);
		expect(run.lines.slice(0, 2)).toEqual(["Wrote 0 page reference rows.", "Deleted 0 404 log rows."]);
	});

	test("a minified adapter (renamed classes) still gives its dialect", () => {
		const minified = (adapter: { supportsReturning: boolean; supportsTransactionalDdl: boolean }) => {
			class a {}
			return { getExecutor: () => ({ adapter: Object.assign(new a(), adapter) }) } as unknown as Kysely<unknown>;
		};
		expect(dialectOf(minified({ supportsReturning: true, supportsTransactionalDdl: true }))).toBe("postgres");
		expect(dialectOf(minified({ supportsReturning: false, supportsTransactionalDdl: false }))).toBe("mysql");
		expect(dialectOf(minified({ supportsReturning: true, supportsTransactionalDdl: false }))).toBe("sqlite");
	});

	test("migrate reads the dialect from the Kysely adapter and is idempotent", async () => {
		const { cms, db } = await makeCms(fixture);
		expect(dialectOf(db as Kysely<unknown>)).toBe(testDialect);
		const run = capture();
		expect(await runCommand(cms, ["migrate"], run.io)).toBe(0);
		expect(run.lines).toEqual([`Migrated (${testDialect}).`]);
	});
});
