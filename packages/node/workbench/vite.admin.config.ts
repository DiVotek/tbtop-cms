import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

// The admin is an Inertia app with its own client: @tbtop/admin answers its
// URLs and points the HTML at this build through the manifest.
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
