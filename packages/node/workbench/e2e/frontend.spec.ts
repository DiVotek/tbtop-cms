import { expect, test } from "@playwright/test";

test("the home page is rendered on the server with its SEO head", async ({ request }) => {
	const response = await request.get("/");
	expect(response.status()).toBe(200);
	const html = await response.text();
	expect(html).toContain("<title>Welcome — tbtop demo</title>");
	expect(html).toMatch(/<link rel="canonical" href="http:\/\/localhost:\d+"\/>/);
	expect(html).toMatch(/<link rel="alternate" hrefLang="uk" href="http:\/\/localhost:\d+\/uk"\/>/);
	expect(html).toContain('"@type":"WebSite"');
	expect(html).toContain('data-testid="hero"');
});

test("blocks render through json-render", async ({ page }) => {
	await page.goto("/");
	await expect(page.getByTestId("hero").getByRole("heading")).toHaveText("Welcome to tbtop CMS");
	await expect(page.getByTestId("contact-form").getByRole("button")).toHaveText("Send");
});

test("a locale prefix picks the translation and the html lang", async ({ page }) => {
	await page.goto("/uk/blog/pershiy-zapis");
	await expect(page.locator("html")).toHaveAttribute("lang", "uk");
	const crumbs = page.getByRole("navigation", { name: "Breadcrumbs" }).getByRole("link");
	await expect(crumbs).toHaveCount(3);
	await expect(crumbs.first()).toHaveText("Головна");
});

test("client navigation loads the next page through the server function", async ({ page }) => {
	await page.goto("/");
	await page.evaluate(() => {
		(window as { stayed?: boolean }).stayed = true;
	});
	await page.getByRole("navigation", { name: "Languages" }).getByRole("link", { name: "Українська" }).click();
	await expect(page).toHaveURL(/\/uk$/);
	await expect(page.locator("html")).toHaveAttribute("lang", "uk");
	await expect(page).toHaveTitle(/^Ласкаво просимо/);
	await expect(page.getByTestId("hero").getByRole("heading")).toHaveText("Вітаємо в tbtop CMS");
	expect(await page.evaluate(() => (window as { stayed?: boolean }).stayed)).toBe(true);
});

test("redirects: a stored redirect and the default-locale prefix", async ({ request }) => {
	const stored = await request.get("/old-about?ref=1", { maxRedirects: 0 });
	expect(stored.status()).toBe(301);
	expect(stored.headers().location).toMatch(/\/about-us\?ref=1$/);
	const prefix = await request.get("/en/about-us", { maxRedirects: 0 });
	expect(prefix.status()).toBe(301);
	expect(prefix.headers().location).toMatch(/\/about-us$/);
});

test("unknown, draft and scheduled pages are 404", async ({ request }) => {
	for (const path of ["/nope", "/work-in-progress", "/blog/coming-soon"]) {
		expect((await request.get(path)).status(), path).toBe(404);
	}
});

test("a draft previews with a banner and noindex", async ({ page }) => {
	await page.goto("/work-in-progress?preview=1");
	await expect(page.getByTestId("preview-banner")).toBeVisible();
	await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", "noindex");
});

test("robots.txt and the sitemap", async ({ request }) => {
	const robots = await request.get("/robots.txt");
	expect(await robots.text()).toContain("Disallow: /admin");
	const index = await (await request.get("/sitemap.xml")).text();
	expect(index).toContain("/sitemap-1.xml</loc>");
	const chunk = await (await request.get("/sitemap-1.xml")).text();
	expect(chunk).toContain("/blog/first-post</loc>");
	expect(chunk).not.toContain("work-in-progress");
});
