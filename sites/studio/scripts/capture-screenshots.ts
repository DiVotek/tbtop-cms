import { chromium } from "@playwright/test";
import { mkdirSync } from "node:fs";

const base = process.env.SCREENSHOT_BASE ?? "http://localhost:3311";
const out = new URL("../screenshots", import.meta.url).pathname;
mkdirSync(out, { recursive: true });

const pages = [
	{ name: "home-desktop", path: "/", width: 1280, height: 900 },
	{ name: "home-mobile", path: "/", width: 390, height: 844 },
	{ name: "journal-desktop", path: "/journal", width: 1280, height: 900 },
	{ name: "journal-mobile", path: "/journal", width: 390, height: 844 },
];

const browser = await chromium.launch();
for (const shot of pages) {
	const page = await browser.newPage({ viewport: { width: shot.width, height: shot.height } });
	await page.goto(`${base}${shot.path}`, { waitUntil: "networkidle" });
	await page.screenshot({ path: `${out}/${shot.name}.png`, fullPage: true });
	await page.close();
}
await browser.close();
console.log(`saved to ${out}`);
