import { describe, expect, test } from "bun:test";
import type { Kysely } from "kysely";
import { type CmsDatabase, cmsBlockImpls, cmsBlocks, createCms, demoFixture, implementBlocks, migrateCms, seedFixtures } from "../src";
import { d1Kysely } from "../src/cloudflare/d1";
import type { D1Database, D1PreparedStatement } from "../src/cloudflare/types";
import { fakeD1 } from "./fakeD1";
import { get } from "./support";

// A page render on Workers pays a D1 round trip per query; what costs is the
// longest chain of queries that wait on each other. A D1 binding with a fixed
// latency turns that chain into wall time: concurrent queries overlap, a
// chain adds up.
const LATENCY = 25;

function slowD1() {
	const inner = fakeD1();
	const state = { on: false, queries: [] as string[] };
	const wrap = (statement: D1PreparedStatement, sql: string): D1PreparedStatement => {
		const delayed =
			<A extends unknown[], R>(call: (...args: A) => Promise<R>) =>
			async (...args: A): Promise<R> => {
				if (state.on) {
					state.queries.push(sql);
					await Bun.sleep(LATENCY);
				}
				return call(...args);
			};
		return {
			...statement,
			bind: (...values: unknown[]) => wrap(statement.bind(...values), sql),
			all: delayed(statement.all.bind(statement)),
			run: delayed(statement.run.bind(statement)),
			first: delayed(statement.first.bind(statement)),
			raw: delayed(statement.raw.bind(statement)),
		} as D1PreparedStatement;
	};
	const binding = { ...inner, prepare: (sql: string) => wrap(inner.prepare(sql), sql) } as D1Database;
	return { binding, state };
}

async function site() {
	const { binding, state } = slowD1();
	const db = d1Kysely<CmsDatabase>(binding);
	await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
	const cms = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls), url: "https://site.test" });
	await seedFixtures(cms, demoFixture);
	const render = async (path: string) => {
		state.queries = [];
		state.on = true;
		const started = performance.now();
		const result = await cms.loadPage(get(path));
		state.on = false;
		return { type: result.type, queries: [...state.queries], roundTrips: Math.round((performance.now() - started) / LATENCY) };
	};
	return { render };
}

describe("a page render's database round trips", () => {
	test("the home page and a category with children wait on a few sequential queries, not one per lookup", async () => {
		const { render } = await site();
		// Before batching: `/` 10 queries in 11 round trips, `/blog` 13 in 13.
		const home = await render("/");
		expect(home.type).toBe("page");
		expect(home.queries.length).toBeLessThanOrEqual(7);
		expect(home.roundTrips).toBeLessThanOrEqual(6);
		const blog = await render("/blog");
		expect(blog.type).toBe("page");
		expect(blog.queries.length).toBeLessThanOrEqual(9);
		expect(blog.roundTrips).toBeLessThanOrEqual(7);
	});
});
