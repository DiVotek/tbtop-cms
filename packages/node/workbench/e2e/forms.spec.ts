import { expect, test } from "@playwright/test";

test("the contact form shows field errors, then the success message, and the lead lands in the inbox", async ({ page }) => {
	await page.goto("/");
	const form = page.getByTestId("contact-form");
	await form.getByLabel("Name").fill("Ada Lovelace");
	await form.getByLabel("Email").fill("not-an-email");
	await form.getByRole("button", { name: "Send" }).click();
	await expect(form.getByRole("alert").first()).toBeVisible();

	await form.getByLabel("Email").fill("ada@example.test");
	await form.getByLabel("Message").fill("Please call me back.");
	await form.getByRole("button", { name: "Send" }).click();
	await expect(page.getByTestId("contact-form").getByRole("status")).toHaveText("Thanks, we will be in touch.");

	await page.goto("/admin/form-submissions");
	const row = page.getByRole("row", { name: /Ada Lovelace · ada@example\.test/ });
	await expect(row).toContainText("New");
	await row.getByText("Ada Lovelace · ada@example.test").click();
	await expect(page.getByText("Please call me back.")).toBeVisible();

	await page.goto("/admin/form-submissions");
	await expect(page.getByRole("row", { name: /Ada Lovelace/ })).toContainText("Read");
});

test("a filled honeypot looks sent and stores nothing", async ({ request, page }) => {
	const home = await request.get("/");
	const action = /action="([^"]+\/_cms\/forms\/[^"]+)"/.exec(await home.text())?.[1] ?? "";
	expect(action).not.toBe("");
	const response = await request.post(action, { data: { name: "Bot", email: "bot@example.test", message: "spam", website: "x" } });
	expect(await response.json()).toEqual({ ok: true });
	await page.goto("/admin/form-submissions");
	await expect(page.getByRole("row", { name: /Ada Lovelace/ })).toBeVisible();
	await expect(page.getByRole("row", { name: /bot@example\.test/ })).toHaveCount(0);
});

test("without JavaScript the form posts and comes back to the page", async ({ browser }) => {
	const context = await browser.newContext({ javaScriptEnabled: false });
	const page = await context.newPage();
	await page.goto("/");
	const form = page.getByTestId("contact-form");
	await form.getByLabel("Name").fill("Grace");
	await form.getByLabel("Email").fill("grace@example.test");
	await form.getByLabel("Message").fill("Hello");
	await form.getByRole("button", { name: "Send" }).click();
	await expect(page).toHaveURL(/cms_form_status=sent/);
	await context.close();
});
