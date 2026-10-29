import type { Site } from "../site";

/** tbtop-cms.preview.link_ttl_hours. */
const DEFAULT_TTL_HOURS = 72;

/**
 * PreviewLink: a time-limited signed URL for a page that is not live, like
 * URL::temporarySignedRoute. The HMAC (Web Crypto, so it runs on Workers)
 * binds the encoded path and `expires`; other query parameters are ignored.
 * Null without `preview.secret`.
 */
export async function previewLink(site: Site, path: string, locale: string, now = Date.now()): Promise<string | null> {
	const secret = site.config.preview?.secret ?? "";
	if (secret === "") {
		return null;
	}
	const url = new URL(await site.url(path, locale), `${site.origin}/`);
	const ttl = site.config.preview?.ttlHours ?? DEFAULT_TTL_HOURS;
	const expires = String(Math.floor(now / 1000) + Math.round(ttl * 3600));
	url.search = new URLSearchParams({ expires, signature: await hmac(secret, payload(url.pathname, expires)) }).toString();
	return url.toString();
}

/** The `signed` middleware: the request carries an unexpired signature for its own path. */
export async function hasValidPreviewSignature(site: Site, request: Request): Promise<boolean> {
	const secret = site.config.preview?.secret ?? "";
	const url = new URL(request.url);
	const expires = url.searchParams.get("expires") ?? "";
	const signature = url.searchParams.get("signature") ?? "";
	if (secret === "" || !/^[0-9]+$/.test(expires) || Number(expires) * 1000 < Date.now()) {
		return false;
	}
	return timingSafeEqual(await hmac(secret, payload(url.pathname, expires)), signature);
}

const payload = (pathname: string, expires: string) => `${pathname}?expires=${expires}`;

async function hmac(secret: string, message: string): Promise<string> {
	const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
	const mac = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(message));
	return [...new Uint8Array(mac)].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

function timingSafeEqual(a: string, b: string): boolean {
	if (a.length !== b.length) {
		return false;
	}
	let diff = 0;
	for (let i = 0; i < a.length; i++) {
		diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
	}
	return diff === 0;
}
