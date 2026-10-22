import { defineConfig } from "@playwright/test";

const port = 3210;

// E2E against the production build: `vite build`, then `vite preview` on a
// fresh database that the first request migrates and seeds.
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
	webServer: {
		command: `rm -f e2e.sqlite e2e-mailbox.jsonl && bun run build && CMS_DB=e2e.sqlite CMS_MAILBOX=e2e-mailbox.jsonl vite preview --port ${port}`,
		url: `http://localhost:${port}/robots.txt`,
		timeout: 120_000,
		reuseExistingServer: false,
	},
});
