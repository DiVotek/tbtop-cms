import { tanstackStart } from "@tanstack/react-start/plugin/vite";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

export default defineConfig({
	server: { port: 3200, strictPort: true },
	preview: { port: 3200, strictPort: true },
	plugins: [tanstackStart(), react()],
	// Native, server-only: never pre-bundled for the browser.
	ssr: { external: ["better-sqlite3"] },
});
