import { expect, test } from "@playwright/test";

const pages = [
	{ name: "home", path: "/" },
	{ name: "catalog", path: "/catalog" },
	{ name: "production", path: "/nase-virobnictvo" },
	{ name: "contacts", path: "/iak-nas-znaiti" },
] as const;

for (const { name, path } of pages) {
	test(`screenshot ${name} desktop`, async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });
		await page.goto(path);
		await expect(page.getByTestId("site-nav")).toBeVisible();
		await page.screenshot({ path: `artifacts/screenshots/${name}-desktop.png`, fullPage: true });
	});

	test(`screenshot ${name} mobile`, async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 });
		await page.goto(path);
		await expect(page.getByTestId("site-nav")).toBeVisible();
		await page.screenshot({ path: `artifacts/screenshots/${name}-mobile.png`, fullPage: true });
	});
}
