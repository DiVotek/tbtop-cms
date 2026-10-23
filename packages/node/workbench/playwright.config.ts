import { defineConfig } from "@playwright/test";

const port = 3210;

// E2E against the production build: `vite build`, then `vite preview` on a
// fresh database that the first request migrates and seeds. NODE_ENV is
// production, as on a deployed host: a block whose props fail the catalog is
// dropped with a warning instead of throwing (q05). The form throttle is off,
// since every spec posts from the same client. The setup project signs in as
// the seeded admin once; every spec reuses that Better Auth session.
export default defineConfig({
	testDir: "e2e",
	workers: 1,
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	reporter: process.env.CI ? [["github"], ["list"]] : "list",
	use: {
		baseURL: `http://localhost:${port}`,
		launchOptions: process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {},
		trace: "retain-on-failure",
	},
	projects: [
		{ name: "setup", testMatch: /auth\.setup\.ts/ },
		{ name: "e2e", dependencies: ["setup"], use: { storageState: "e2e/.auth/admin.json" } },
	],
	webServer: {
		command: `rm -f e2e.sqlite e2e-mailbox.jsonl && bun run build && NODE_ENV=production CMS_FORMS_THROTTLE=off CMS_DB=e2e.sqlite CMS_MAILBOX=e2e-mailbox.jsonl vite preview --port ${port}`,
		url: `http://localhost:${port}/robots.txt`,
		timeout: 120_000,
		reuseExistingServer: false,
	},
});
