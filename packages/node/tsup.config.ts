import { defineConfig } from "tsup";

// One entry per public export; splitting keeps shared classes in one chunk.
export default defineConfig({
	entry: {
		index: "src/index.ts",
		catalog: "src/blocks/catalog.ts",
	},
	format: ["esm"],
	target: "es2022",
	platform: "neutral",
	dts: true,
	splitting: true,
	sourcemap: true,
	clean: true,
	treeshake: true,
	// Runtime deps and peers stay external.
	external: ["zod", "kysely", "@tbtop/admin", /^@tbtop\/admin\//, /^node:/],
	removeNodeProtocol: false,
});
