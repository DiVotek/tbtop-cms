import { defineConfig } from "@playwright/test";

const port = 3310;

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
	projects: [{ name: "smoke", testMatch: /smoke\.spec\.ts/ }],
	webServer: {
		command: `rm -f e2e.sqlite e2e-mailbox.jsonl && bun run build && NODE_ENV=production CMS_FORMS_THROTTLE=off CMS_DB=e2e.sqlite CMS_MAILBOX=e2e-mailbox.jsonl CMS_URL=http://localhost:${port} BETTER_AUTH_URL=http://localhost:${port} vite preview --port ${port}`,
		url: `http://localhost:${port}/robots.txt`,
		timeout: 180_000,
		reuseExistingServer: false,
	},
});
