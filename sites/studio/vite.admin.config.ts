import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

export default defineConfig({
	base: "/admin-build/",
	publicDir: false,
	plugins: [react(), tailwindcss()],
	build: {
		manifest: true,
		outDir: "public/admin-build",
		emptyOutDir: true,
		rollupOptions: { input: "src/admin-client.tsx" },
	},
});
