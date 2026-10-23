#!/usr/bin/env node
import { existsSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import type { Cms } from "../cms";
import { runCommand } from "./run";

const CANDIDATES = ["cms.config.ts", "cms.config.mts", "cms.config.js", "cms.config.mjs"];

async function loadCms(args: string[]): Promise<Cms> {
	const index = args.indexOf("--config");
	const path = index === -1 ? CANDIDATES.find((file) => existsSync(file)) : args[index + 1];
	if (path === undefined) {
		throw new Error(`No ${CANDIDATES.join(" / ")} here: pass --config <file>.`);
	}
	const mod = (await import(pathToFileURL(resolve(path)).href)) as { default?: unknown };
	const exported = typeof mod.default === "function" ? await (mod.default as () => unknown)() : mod.default;
	if (exported === null || typeof exported !== "object" || !("tasks" in exported)) {
		throw new Error(`${path} must default-export the host's Cms (createCms(...)) or a function returning it.`);
	}
	return exported as Cms;
}

const args = process.argv.slice(2);
const io = { out: (line: string) => console.log(line), err: (line: string) => console.error(line) };
let code = 1;
let cms: Cms | null = null;
try {
	cms = args[0] === undefined || args[0] === "help" || args[0] === "--help" ? null : await loadCms(args);
	code = cms === null ? await runCommand({} as Cms, ["help"], io) : await runCommand(cms, args, io);
} catch (error) {
	io.err(error instanceof Error ? error.message : String(error));
} finally {
	await cms?.config.db.destroy();
}
process.exit(code);
