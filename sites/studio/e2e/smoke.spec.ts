import { expect, test } from "@playwright/test";

test("home: SSR title, hero, layout nav", async ({ page }) => {
	await page.goto("/");
	await expect(page.locator("html")).toHaveAttribute("lang", "en");
	await expect(page).toHaveTitle(/Calm products/);
	await expect(page.getByTestId("studio-hero")).toBeVisible();
	await expect(page.getByTestId("site-nav")).toBeVisible();
	await expect(page.getByTestId("newsletter-form")).toBeVisible();
});

test("journal: list, pagination, article", async ({ page }) => {
	await page.goto("/journal");
	await expect(page.getByTestId("journal-index")).toBeVisible();
	await expect(page.getByRole("link", { name: "Next" })).toBeVisible();
	await page.getByRole("link", { name: "Design tokens that survive handoff" }).click();
	await expect(page.getByTestId("article-meta")).toContainText("design-systems");
	await expect(page.getByTestId("page-body")).toContainText("Tokens are contracts");
});

test("locale prefix and German home", async ({ page }) => {
	await page.goto("/de");
	await expect(page.locator("html")).toHaveAttribute("lang", "de");
	await expect(page.getByTestId("studio-hero")).toContainText("leuchtende");
});

test("contact form submits", async ({ request, page }) => {
	const html = await (await request.get("/get-in-touch")).text();
	const action = /action="([^"]+\/_cms\/forms\/[^"]+)"/.exec(html)?.[1] ?? "";
	expect(action).not.toBe("");
	const response = await request.post(action, {
		headers: { Accept: "application/json", "Content-Type": "application/json" },
		data: { name: "Ada Lovelace", email: "ada@example.test", message: "Hello from Playwright smoke test." },
	});
	expect(await response.json()).toEqual({ ok: true });
	await page.goto("/get-in-touch");
	await expect(page.getByTestId("contact-form")).toBeVisible();
});

test("redirects: manual old-contact and auto slug change", async ({ request }) => {
	const manual = await request.get("/old-contact", { maxRedirects: 0 });
	expect(manual.status()).toBe(301);
	expect(manual.headers().location).toMatch(/get-in-touch/);
	const auto = await request.get("/contact", { maxRedirects: 0 });
	expect(auto.status()).toBe(301);
	expect(auto.headers().location).toMatch(/get-in-touch/);
});

test("robots, sitemap, rss", async ({ request }) => {
	expect(await (await request.get("/robots.txt")).text()).toContain("Disallow: /admin");
	const sitemap = await (await request.get("/sitemap-1.xml")).text();
	expect(sitemap).toContain("/journal/");
	expect(sitemap).not.toContain("Draft essay");
	const rss = await (await request.get("/feed.xml")).text();
	expect(rss).toContain("<rss");
	expect(rss).toContain("Design tokens");
});

test("scheduled and draft posts 404", async ({ request }) => {
	const journal = await (await request.get("/journal")).text();
	expect(journal).not.toContain("Draft essay");
	expect((await request.get("/journal/coming-soon-2027-roadmap")).status()).toBe(404);
});
