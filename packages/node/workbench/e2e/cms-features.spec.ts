import { expect, test } from "@playwright/test";

test("a link field picked in the admin becomes the CTA href on the site", async ({ page }) => {
	await page.goto("/admin/pages");
	await page.getByRole("row", { name: /Home/ }).getByRole("cell").first().click();
	await page.getByRole("tab", { name: /Promo/ }).click();
	const panel = page.getByRole("tabpanel");

	// The link client (@tbtop/cms/admin-client) renders, not the unknown-field placeholder.
	// The page picker is named by the field's label ("Button").
	const picker = panel.getByRole("button", { name: "Button" });
	await expect(picker).toHaveText("Search pages…");
	await picker.click();
	await page.getByLabel("Search pages").fill("About");
	await page.getByRole("button", { name: "About us" }).click();
	await expect(picker).toHaveText("About us");
	await panel.getByLabel("Text (en)").fill("Meet the team");
	await page.getByRole("button", { name: "Save" }).last().click();
	await expect(page.getByText(/saved/i).first()).toBeVisible();

	await page.goto("/");
	const cta = page.getByTestId("promo").getByRole("link");
	await expect(cta).toHaveText("Meet the team");
	await expect(cta).toHaveAttribute("href", "/about-us");
	// A page link follows the locale: no label in uk, so the page's own name.
	await page.goto("/uk");
	await expect(page.getByTestId("promo").getByRole("link")).toHaveAttribute("href", "/uk/pro-nas");
});

test("a draft opens through the page editor's signed preview link, and only through it", async ({ page, request }) => {
	expect((await request.get("/work-in-progress?preview=1")).status()).toBe(404);

	await page.goto("/admin/pages");
	await page.getByRole("row", { name: /Work in progress/ }).getByRole("cell").first().click();
	const href = await page.getByRole("link", { name: "Preview (en)" }).getAttribute("href");
	expect(href).toMatch(/\/work-in-progress\?expires=\d+&signature=[0-9a-f]+$/);

	await page.goto(href ?? "");
	await expect(page.getByTestId("preview-banner")).toBeVisible();
	await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", "noindex");

	const forged = (href ?? "").replace(/signature=([0-9a-f])/, (_m, first: string) => `signature=${first === "0" ? "1" : "0"}`);
	expect((await request.get(forged)).status()).toBe(404);
});

test("an editor-made 404 page answers unknown URLs with noindex", async ({ page, request }) => {
	await page.goto("/admin/pages");
	await page.getByRole("row", { name: /Page not found/ }).getByRole("cell").first().click();
	await expect(page.getByRole("heading", { name: "Page not found" })).toBeVisible();
	const heading = page.locator('[id="heading.en"]');
	await expect(heading).toHaveValue("Page not found");
	await heading.fill("Nothing lives here");
	await page.getByRole("button", { name: "Save" }).last().click();
	await expect(page.getByText(/saved/i).first()).toBeVisible();

	const response = await request.get("/no-such-page");
	expect(response.status()).toBe(404);
	const html = await response.text();
	expect(html).toContain("Nothing lives here");
	expect(html).toMatch(/<meta name="robots" content="noindex[^"]*"/);

	await page.goto("/no-such-page");
	await expect(page.getByRole("heading", { level: 1 })).toHaveText("Nothing lives here");
});

test("a GTM container set in the SEO settings puts both halves of the tag on the site", async ({ page, request }) => {
	const setGtm = async (value: string) => {
		await page.goto("/admin/settings/seo");
		await page.getByRole("tab", { name: "Integrations" }).click();
		await page.getByLabel("Google Tag Manager container ID").fill(value);
		await page.getByRole("button", { name: "Save" }).click();
		await expect(page.getByText(/saved/i).first()).toBeVisible();
	};
	await setGtm("GTM-ABC1234");

	const html = await (await request.get("/")).text();
	expect(html).toContain("googletagmanager.com/gtm.js?id=");
	expect(html).toContain("GTM-ABC1234");
	expect(html).toMatch(/<body><noscript><iframe src="https:\/\/www\.googletagmanager\.com\/ns\.html\?id=GTM-ABC1234"/);

	// Cleared again, so no other spec loads Google's script.
	await setGtm("");
	expect(await (await request.get("/")).text()).not.toContain("googletagmanager.com");
});
