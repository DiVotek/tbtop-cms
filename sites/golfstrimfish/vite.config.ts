import tailwindcss from "@tailwindcss/vite";
import { tanstackStart } from "@tanstack/react-start/plugin/vite";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

export default defineConfig({
	// Cursor (and similar) tunnel URLs use a random *.cursorvm.com host; Vite blocks them by default.
	server: { port: 3300, strictPort: true, host: true, allowedHosts: [".cursorvm.com", "localhost"] },
	preview: { port: 3300, strictPort: true, allowedHosts: [".cursorvm.com", "localhost"] },
	plugins: [tanstackStart(), react(), tailwindcss()],
	ssr: { external: ["better-sqlite3"] },
});
