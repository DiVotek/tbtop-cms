import { SESSION_USER, type SessionStore } from "@tbtop/admin";

/** The signed-in user as the panel sees it (`currentContext().user`). */
export type CmsAuthUser = { id: string; name: string; email: string; role: string; image: string | null };

/**
 * The panel's session over Better Auth: `user` is the Better Auth session's
 * user (resolved once per request), everything else (flashed errors and
 * effects) lives in the base store. Forgetting the user (the panel's logout)
 * signs out in Better Auth; the cookies Better Auth sets on sign-in and
 * sign-out ride on the response the panel answers.
 */
export class AuthSession implements SessionStore {
	private readonly cookies: string[] = [];

	constructor(
		private readonly base: SessionStore,
		private user: CmsAuthUser | null,
		private readonly signOut: () => Promise<Response>,
	) {}

	get(key: string): unknown {
		return key === SESSION_USER ? this.user : this.base.get(key);
	}

	set(key: string, value: unknown): void | Promise<void> {
		if (key === SESSION_USER) {
			throw new TypeError("Sign in through Better Auth: the panel's user is its session's user.");
		}
		return this.base.set(key, value);
	}

	async forget(key: string): Promise<void> {
		if (key !== SESSION_USER) {
			return this.base.forget(key);
		}
		if (this.user !== null) {
			this.carry(await this.signOut());
			this.user = null;
		}
	}

	/** Keeps the Set-Cookie headers of a Better Auth answer for the panel's response. */
	carry(response: Response): void {
		this.cookies.push(...response.headers.getSetCookie());
	}

	async commit(response: Response): Promise<Response> {
		const committed = (await this.base.commit?.(response)) ?? response;
		if (this.cookies.length === 0) {
			return committed;
		}
		const out = new Response(committed.body, committed);
		for (const cookie of this.cookies) {
			out.headers.append("set-cookie", cookie);
		}
		return out;
	}
}
