import { expect, test } from "@playwright/test";

test("home page renders with SEO and main sections", async ({ page, request }) => {
	const response = await request.get("/");
	expect(response.status()).toBe(200);
	const html = await response.text();
	expect(html).toContain("<title>");
	expect(html).toContain("Гольфстрім");
	expect(html).toMatch(/rel="canonical"/);
	expect(html).toContain('"@type":"WebSite"');

	await page.goto("/");
	await expect(page.getByTestId("site-nav")).toBeVisible();
	await expect(page.getByTestId("hero")).toBeVisible();
	await expect(page.getByTestId("category-cards")).toBeVisible();
	await expect(page.locator("html")).toHaveAttribute("lang", "uk");
});

test("catalog and product pages", async ({ page }) => {
	await page.goto("/catalog");
	await expect(page.getByTestId("product-grid")).toBeVisible();
	await expect(page.getByTestId("product-grid").getByRole("link")).not.toHaveCount(0);

	await page.goto("/catalog/holodne-kopchennya");
	await page.getByTestId("product-grid").getByRole("link").first().click();
	await expect(page.getByTestId("product-detail")).toBeVisible();
});

test("contacts page and contact form", async ({ page }) => {
	await page.goto("/iak-nas-znaiti");
	await expect(page.getByTestId("store-list")).toBeVisible();
	const form = page.getByTestId("contact-form");
	await form.getByLabel("Ім’я").fill("Тест Клієнт");
	await form.getByLabel("Електронна пошта").fill("client@example.test");
	await form.getByLabel("Повідомлення").fill("Цікавить оптова поставка.");
	await form.getByRole("button", { name: "Надіслати" }).click();
	await expect(form.getByRole("status")).toContainText("Дякуємо");
});

test("redirect from legacy path", async ({ request }) => {
	const res = await request.get("/kontakty", { maxRedirects: 0 });
	expect(res.status()).toBe(301);
	expect(res.headers().location).toMatch(/iak-nas-znaiti/);
});

test("robots and sitemap", async ({ request }) => {
	expect(await (await request.get("/robots.txt")).text()).toContain("Disallow: /admin");
	const index = await (await request.get("/sitemap.xml")).text();
	expect(index).toContain("sitemap-1.xml");
});
