import { expect, test as setup } from "@playwright/test";

// The seeded admin signs in through the panel's Better Auth sign-in page.
setup("sign in as the seeded admin", async ({ page }) => {
	await page.goto("/admin/pages");
	await expect(page).toHaveURL(/\/admin\/login$/);
	await page.locator("#email").fill("admin@admin.com");
	await page.locator("#password").fill("password");
	await page.getByRole("button", { name: "Sign in" }).click();
	await expect(page).toHaveURL(/\/admin\/dashboard$/);
	await page.context().storageState({ path: "e2e/.auth/admin.json" });
});
