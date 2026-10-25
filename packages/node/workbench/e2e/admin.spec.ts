import { expect, test } from "@playwright/test";

test("the pages table lists the seeded pages with their paths", async ({ page }) => {
	await page.goto("/admin/pages");
	const about = page.getByRole("row", { name: /About us/ });
	await expect(about).toContainText("/about-us");
	await expect(page.getByRole("row", { name: /Work in progress/ })).toContainText("Draft");
});

test("an edit in the admin shows on the site, per locale", async ({ page }) => {
	await page.goto("/admin/pages");
	await page.getByRole("row", { name: /About us/ }).getByText("Edit").click();
	await expect(page.getByRole("heading", { name: "About us" })).toBeVisible();

	// A translatable field's label points at "heading", its inputs are "heading.<locale>".
	await page.locator('[id="heading.en"]').fill("We build small sites");
	await page.getByRole("tab", { name: "uk" }).click();
	await page.locator('[id="heading.uk"]').fill("Ми робимо сайти");
	// The status bar and the footer both save; the footer one carries mod+s.
	await page.getByRole("button", { name: "Save" }).last().click();
	await expect(page.getByText(/saved/i).first()).toBeVisible();

	await page.goto("/about-us");
	await expect(page.getByRole("heading", { level: 1 })).toHaveText("We build small sites");
	await page.goto("/uk/pro-nas");
	await expect(page.getByRole("heading", { level: 1 })).toHaveText("Ми робимо сайти");
});

test("the languages page lists both locales", async ({ page }) => {
	await page.goto("/admin/languages");
	await expect(page.getByRole("row", { name: /English/ })).toBeVisible();
	await expect(page.getByRole("row", { name: /Українська/ })).toBeVisible();
});

test("an uploaded page image becomes the social image", async ({ page }) => {
	await page.goto("/admin/pages");
	await page.getByRole("row", { name: /First post/ }).getByText("Edit").click();
	// The upload posts on its own; saving before it answers would store no image.
	const uploaded = page.waitForResponse((res) => res.request().method() === "POST" && /\/uploads\/[^/]+$/.test(new URL(res.url()).pathname));
	await page.locator('input[type="file"]').first().setInputFiles("e2e/pixel.png");
	expect((await uploaded).ok()).toBe(true);
	await page.getByRole("button", { name: "Save" }).last().click();
	await expect(page.getByText(/^Page saved/).first()).toBeVisible();

	await page.goto("/blog/first-post");
	const image = await page.locator('meta[property="og:image"]').getAttribute("content");
	expect(image).toMatch(/\/storage\/.+\.png$/);
	expect((await page.request.get(image ?? "")).status()).toBe(200);
});

test("a block edit shows on the home page", async ({ page }) => {
	await page.goto("/admin/pages");
	await page.getByRole("row", { name: /Home/ }).getByText("Edit").click();
	await page.getByRole("tab", { name: /Hero/ }).click();
	// The subtitle, so the frontend specs keep the seeded title.
	await page.locator('[id$="_subtitle.en"]').fill("A fresh subtitle");
	await page.getByRole("button", { name: "Save" }).last().click();
	await expect(page.getByText(/saved/i).first()).toBeVisible();

	await page.goto("/");
	await expect(page.getByTestId("hero").getByRole("paragraph")).toHaveText("A fresh subtitle");
});
