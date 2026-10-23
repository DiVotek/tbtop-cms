import type { FormsConfig } from "../config";

/** `forms.throttle`: at most `max` posts per client in `perSeconds` (PHP `throttle:10,1`). */
export type ThrottleLimit = { max: number; perSeconds: number };

const DEFAULT_LIMIT: ThrottleLimit = { max: 10, perSeconds: 60 };

/** Past this many tracked clients, expired windows are swept before a new one is added. */
const SWEEP_AT = 10_000;

/**
 * Laravel's RateLimiter as the `throttle` middleware uses it: a fixed window
 * per key that opens on the first hit. In memory, so it counts per process
 * (per isolate on Workers); a host that needs one count across instances
 * passes its own limiter as `forms.allow`.
 */
export class SubmitThrottle {
	private readonly windows = new Map<string, { hits: number; resetAt: number }>();

	constructor(private readonly limit: ThrottleLimit) {}

	/** Counts the hit; the seconds until the window resets when it is over the limit, else null. */
	hit(key: string, now = Date.now()): number | null {
		let window = this.windows.get(key);
		if (window === undefined || window.resetAt <= now) {
			this.sweep(now);
			window = { hits: 0, resetAt: now + this.limit.perSeconds * 1000 };
			this.windows.set(key, window);
		}
		if (window.hits >= this.limit.max) {
			return Math.max(1, Math.ceil((window.resetAt - now) / 1000));
		}
		window.hits++;
		return null;
	}

	private sweep(now: number): void {
		if (this.windows.size < SWEEP_AT) {
			return;
		}
		for (const [key, window] of this.windows) {
			if (window.resetAt <= now) {
				this.windows.delete(key);
			}
		}
	}
}

/**
 * The built-in limiter for a config: an explicit `throttle` wins, `false`
 * turns it off, and with neither the host's own `allow` replaces the
 * default, as a PHP host swaps `forms.throttle` for its own limiter.
 */
export function submitThrottle(forms: FormsConfig | undefined): SubmitThrottle | null {
	const configured = forms?.throttle;
	if (configured === false || (configured === undefined && forms?.allow !== undefined)) {
		return null;
	}
	const limit = configured ?? DEFAULT_LIMIT;
	if (!(Number.isInteger(limit.max) && limit.max > 0 && Number.isFinite(limit.perSeconds) && limit.perSeconds > 0)) {
		throw new Error("forms.throttle needs a positive integer `max` and a positive `perSeconds`.");
	}
	return new SubmitThrottle(limit);
}

/**
 * The client a hit counts against. A Fetch Request carries no socket
 * address, so without the host's `clientIp` this reads proxy headers: the
 * last X-Forwarded-For entry (the one the nearest proxy appended, which a
 * client cannot choose; Cloudflare appends too), then CF-Connecting-IP and
 * X-Real-IP. With no proxy in front every header is the client's own, so
 * such a host passes `clientIp` (Bun: `server.requestIP(request)?.address`).
 * No address at all shares one bucket, which errs toward limiting.
 */
export async function clientKey(request: Request, clientIp: FormsConfig["clientIp"]): Promise<string> {
	if (clientIp !== undefined) {
		return (await clientIp(request)) ?? "unknown";
	}
	const forwarded = request.headers.get("x-forwarded-for")?.split(",").map((part) => part.trim()).filter((part) => part !== "");
	return forwarded?.at(-1) ?? request.headers.get("cf-connecting-ip")?.trim() ?? request.headers.get("x-real-ip")?.trim() ?? "unknown";
}
