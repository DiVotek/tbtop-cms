import { describe, expect, test } from "bun:test";
import { Kysely, MssqlAdapter, MysqlDialect, PostgresDialect } from "kysely";
import { d1Kysely } from "./kit/d1";
import { dialectOf } from "./kit/dialect";
import { fakeD1 } from "./shared/fakeD1";
import { memoryDb } from "./shared/prove";

// The adapter is read without connecting, so the pools below are never used.
const unused = {} as never;

describe("dialectOf pins each adapter", () => {
	test("bun:sqlite (its own Kysely copy) is sqlite", () => expect(dialectOf(memoryDb())).toBe("sqlite"));
	test("D1 is sqlite", () => expect(dialectOf(d1Kysely(fakeD1()))).toBe("sqlite"));
	test("postgres", () => expect(dialectOf(new Kysely<unknown>({ dialect: new PostgresDialect({ pool: unused }) }))).toBe("postgres"));
	test("mysql", () => expect(dialectOf(new Kysely<unknown>({ dialect: new MysqlDialect({ pool: unused }) }))).toBe("mysql"));
	test("anything else asks for an explicit dialect", () =>
		expect(() => {
			const postgres = new PostgresDialect({ pool: unused });
			const mssql = { createAdapter: () => new MssqlAdapter(), createDriver: () => postgres.createDriver(), createQueryCompiler: () => postgres.createQueryCompiler(), createIntrospector: (db: Kysely<unknown>) => postgres.createIntrospector(db) };
			return dialectOf(new Kysely<unknown>({ dialect: mssql }));
		}).toThrow("pass `dialect` explicitly"));
});
