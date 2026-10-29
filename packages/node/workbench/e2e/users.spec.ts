import { expect, test } from "@playwright/test";

test("a wrong password stays on the sign-in page", async ({ browser }) => {
	const guest = await browser.newPage({ storageState: { cookies: [], origins: [] } });
	await guest.goto("/admin/login");
	await guest.locator("#email").fill("admin@admin.com");
	await guest.locator("#password").fill("not-the-password");
	await guest.getByRole("button", { name: "Sign in" }).click();
	await expect(guest.getByText("These credentials do not match an admin account.")).toBeVisible();
	await expect(guest).toHaveURL(/\/admin\/login$/);
	await guest.close();
});

test("an admin adds an editor, who signs in and sees only the editor's sections", async ({ page, browser }) => {
	await page.goto("/admin/users");
	await expect(page.getByRole("row", { name: /admin@admin\.com/ })).toBeVisible();
	await page.getByText("Add user").click();
	await page.locator("#name").fill("Writer");
	await page.locator("#email").fill("writer@site.test");
	await page.locator("#password").fill("writer-pass");
	await page.getByRole("button", { name: "Add" }).click();
	await expect(page).toHaveURL(/\/admin\/users$/);
	await expect(page.getByRole("row", { name: /writer@site\.test/ })).toContainText("Editor");

	const writer = await browser.newPage({ storageState: { cookies: [], origins: [] } });
	await writer.goto("/admin/login");
	await writer.locator("#email").fill("writer@site.test");
	await writer.locator("#password").fill("writer-pass");
	await writer.getByRole("button", { name: "Sign in" }).click();
	await expect(writer).toHaveURL(/\/admin\/dashboard$/);
	await writer.goto("/admin/pages");
	await expect(writer.getByRole("row", { name: /About us/ })).toBeVisible();
	await expect(writer.getByRole("link", { name: "Users" })).toHaveCount(0);
	expect((await writer.request.get("/admin/settings/site")).status()).toBe(403);
	await writer.close();
});
