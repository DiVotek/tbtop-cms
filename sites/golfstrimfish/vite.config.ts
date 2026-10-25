import tailwindcss from "@tailwindcss/vite";
import { tanstackStart } from "@tanstack/react-start/plugin/vite";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

export default defineConfig({
	server: { port: 3300, strictPort: true },
	preview: { port: 3300, strictPort: true },
	plugins: [tanstackStart(), react(), tailwindcss()],
	ssr: { external: ["better-sqlite3"] },
});
