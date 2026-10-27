import type { HostRule, NotificationStore, PageDef, S, SessionResolver } from "@tbtop/admin";
import type { Kysely } from "kysely";
import type { CmsAbility } from "./admin/abilities";
import type { BlockRegistry } from "./blocks/registry";
import type { Cms } from "./cms";
import type { CmsDatabase } from "./db/schema";
import type { PageTypeDef } from "./content/pageTypes";
import type { SubmissionEvent } from "./forms/submit";
import type { Channel, DeliveryEvent, MailTransport, NotificationType } from "./notifications/types";
import type { SchemaContext } from "./seo/schemaContext";
import type { SchemaTypeDef } from "./seo/schemaTypes";
import type { CmsCacheStore } from "./support/cache";

/** What the CMS needs from an auth adapter; `cmsAuth()` returns one. */
export type CmsAdminAuth = {
	authorize: (user: unknown, ability: CmsAbility) => boolean | Promise<boolean>;
	session: SessionResolver;
	loginPath: (cms: Cms) => string;
	pages: (cms: Cms) => PageDef[];
};

/** What a media reference (an upload path or URL) resolves to; width/height/alt feed og:image. */
export type MediaDetails = { url: string; alt?: string | null; mime?: string | null; width?: number | null; height?: number | null };

/** A library image for `<img src srcset width height alt>` (ResolveContext::mediaImage); srcset null means render `src` alone. */
export type MediaImage = { src: string; srcset: string | null; width: number | null; height: number | null; alt: string | null };

export type CmsConfig<T extends readonly PageTypeDef[] = readonly PageTypeDef[]> = {
	/** The host's Kysely instance over a database the package migrations ran on. */
	db: Kysely<CmsDatabase>;
	/** The block kinds: `implementBlocks({ ...cmsBlocks, ...mine }, { ...cmsBlockImpls, ...myImpls })`. */
	blocks: BlockRegistry;
	/** Extra page types next to the built-in page, category and article. */
	pageTypes?: T;
	/** Extra JSON-LD schema types next to the built-in WebPage family, Article family and CollectionPage. */
	schemaTypes?: SchemaTypeDef[];
	/**
	 * The site's IANA timezone (`Europe/Kyiv`), as Laravel's app.timezone:
	 * the admin formats and reads dates in it, the dashboard counts days in
	 * it, and SEO templates print `{date}` in it. Stored dates stay UTC.
	 * Default UTC.
	 */
	timezone?: string;
	/** The public site origin (`https://example.com`); default: the request's origin. */
	url?: string;
	/** A media reference to its public URL; default: absolute URLs and `/…` paths as is, other paths under `/storage/`. */
	media?: (ref: string) => MediaDetails | null;
	/**
	 * A resized copy of a media reference at `width` pixels (a CDN's resize
	 * URL: Cloudflare Images, imgix). With it `ctx.mediaImage()` lists a srcset
	 * at 640/960/1280/1920 below the original's width; without it, or when
	 * `media()` gives no width, srcset is null.
	 */
	imageLoader?: (ref: string, width: number) => string;
	/** Settings keys holding secrets: sealed with `settingsKey`, never sent to the frontend. */
	secretSettings?: string[];
	/**
	 * The host's own tabs on Site settings, after Branding and Contacts. A
	 * field named `tagline` is stored at `site.tagline`, so it reaches the
	 * frontend in `cms.settings` (unless listed in `secretSettings`) and
	 * reads back with `site.settings.get("site.tagline")`. A name the page
	 * already uses throws.
	 */
	siteSettings?: { tabs: (s: S) => { label: string; children: unknown[] }[] };
	/**
	 * The key secret settings are sealed with (AES-256-GCM over Web Crypto).
	 * Keep it in the environment; changing it makes stored secrets unreadable.
	 * Without it a secret setting reads as its fallback and cannot be saved.
	 */
	settingsKey?: string;
	seo?: {
		/**
		 * The page's JSON-LD documents, last: return them with yours added or
		 * replaced. A node of yours with `"@id": identityId(ctx.site)` is the one
		 * Article author and publisher already point to.
		 */
		extend?: (nodes: Record<string, unknown>[], ctx: SchemaContext) => Record<string, unknown>[] | Promise<Record<string, unknown>[]>;
	};
	/** Paths robots.txt disallows besides the admin prefix and `/login`. */
	robotsDisallow?: string[];
	/** The admin URL prefix, disallowed in robots.txt; default `/admin`. */
	adminPrefix?: string;
	/** Pages per sitemap chunk before the locale split; default 1000 (`seo.sitemap_per_chunk` overrides it). */
	sitemapPerChunk?: number;
	sitemap?: boolean;
	/** The json-render root element kind; default `Fragment`. */
	rootKind?: string;
	/**
	 * Throw on a broken block row, or on resolved props failing the catalog's zod schema,
	 * instead of skipping the block with a warning (`tbtop-cms.blocks.strict`). Default off in every environment.
	 */
	strictBlocks?: boolean;
	/** Can this request see drafts (preview)? Default: never, unless it carries a signed preview link (`preview`). */
	canPreview?: (request: Request) => boolean | Promise<boolean>;
	/**
	 * Signed preview links (`tbtop-cms.preview`): the page editor offers a link
	 * per locale for a page that is not live, and loadPage previews a request
	 * whose signature is valid and unexpired. `ttlHours` defaults to 72.
	 * Without a secret there are no links; keep it out of the source.
	 */
	preview?: { secret: string; ttlHours?: number };
	/**
	 * Section access in the admin (`tbtop-cms.roles` + the host's gates): the
	 * panel user and a section ability (`tbtop-cms.pages`, see CMS_ABILITIES).
	 * False hides the section from the nav and answers 403 to its pages and
	 * actions. Without it `auth.authorize` decides, and without both every
	 * section is open to any admin user. For hosts without an auth adapter.
	 */
	authorize?: (user: unknown, ability: CmsAbility) => boolean | Promise<boolean>;
	/**
	 * The panel's auth adapter, given once: `cmsAuth(betterAuth)` from
	 * `@tbtop/cms/better-auth`. Section access, `cms.admin.options()`'s session,
	 * login path and sign-in and Users pages all come from it.
	 */
	auth?: CmsAdminAuth;
	/** Extra frontend strings per locale, over the package's own. */
	strings?: Record<string, Record<string, string>>;
	debug?: boolean;
	forms?: FormsConfig;
	/**
	 * The admin's host validation rules (`kyselyRules(db)` for `unique`/`exists`,
	 * its own): `cms.admin.hostRules` carries them next to the CMS's `link_shape`
	 * and `page_ref`. Frontend forms validate with `submit.schema` instead.
	 */
	hostRules?: Record<string, HostRule>;
	notifications?: NotificationsConfig;
	/** `redirects.allowedExternalHosts`: hosts an external redirect target may use, matched case-insensitively (`tbtop-cms.redirects.allowed_external_hosts`); default none. */
	redirects?: { allowedExternalHosts?: string[] };
	/**
	 * Cross-request cache of the settings and languages that frontend reads
	 * hit on every request (`tbtop-cms.cache`): `memoryCache()` on one server,
	 * `kvCache(env.KV)` on Workers. Admin reads stay live; any write through
	 * the CMS bumps the content version. `ttl` in seconds, default 3600. Off
	 * without a store.
	 */
	cache?: { store: CmsCacheStore; ttl?: number };
	/** The 404 log's noise filter (`tbtop-cms.not_found.ignore`): case-insensitive path fragments; default scanner probes (`.php`, `wp-`, …). */
	notFound?: { ignore?: string[] };
	/** Content snapshots kept per page (`tbtop-cms.revisions.keep`); default 25, 0 turns revisions off. */
	revisions?: { keep?: number };
	/** The admin audit trail: entries older than `retentionDays` (default 365) go in the daily prune. */
	audit?: { retentionDays?: number };
};

export type FormsConfig = {
	/** Store submissions (`tbtop-cms.forms.store`); default true. Without a row nothing is delivered or retried. */
	store?: boolean;
	/** The honeypot input name handed to form blocks; a filled one looks like success and stores nothing. Default `website`, null turns it off. */
	honeypotField?: string | null;
	/**
	 * `inline` (default): notifications go out before the submit answers.
	 * `outbox`: the submission stays pending until `cms.tasks.deliver()` runs,
	 * from a cron, `ctx.waitUntil()` on Workers, or a queue.
	 */
	delivery?: "inline" | "outbox";
	/**
	 * The host's own limiter or bot check before anything runs; false answers
	 * 429. Given alone it replaces the built-in `throttle`, as a PHP host
	 * swaps `forms.throttle` for its own limiter; with `throttle` set both run.
	 */
	allow?: (request: Request) => boolean | Promise<boolean>;
	/**
	 * Built-in rate limit per client (`tbtop-cms.forms.throttle`, `10,1`):
	 * over it a post answers 429 with Retry-After. Default `{ max: 10,
	 * perSeconds: 60 }` unless `allow` is given; false turns it off. Counted in
	 * memory, so per process, and per isolate on Workers.
	 */
	throttle?: { max: number; perSeconds: number } | false;
	/**
	 * After a valid submission is stored (or would be, with `store: false`)
	 * and before the block's own handler runs (SubmissionReceived). A throw is
	 * logged and never fails the submit.
	 */
	onSubmission?: (event: SubmissionEvent) => void | Promise<void>;
	/** The client address the throttle counts by; default: proxy headers (see README). */
	clientIp?: (request: Request) => string | null | undefined | Promise<string | null | undefined>;
};

export type NotificationsConfig = {
	/** Sends mail: nodemailer, Resend, Cloudflare Email, SES. Without it the mail channel is not offered. */
	mail?: MailTransport;
	/** The bot token (`notifications.telegram.bot_token`), from the environment; chat ids are a setting. */
	telegram?: { botToken: string; fetch?: typeof fetch };
	/** The admin bell: the panel's store and the staff users who get it. */
	bell?: { store: NotificationStore; staff: () => unknown[] | Promise<unknown[]> };
	/** Host notification types next to `form.submission`. */
	types?: NotificationType<never>[];
	/** Host channels (SMS, Slack) next to mail, Telegram and the bell. */
	channels?: Channel[];
	/**
	 * After each (recipient, channel) pair is sent or fails, in render or send
	 * (NotificationSent / NotificationFailed). A throw is logged and never
	 * fails the delivery.
	 */
	onDelivery?: (event: DeliveryEvent) => void | Promise<void>;
};

const MIME: Record<string, string> = {
	jpg: "image/jpeg",
	jpeg: "image/jpeg",
	png: "image/png",
	webp: "image/webp",
	avif: "image/avif",
	gif: "image/gif",
	svg: "image/svg+xml",
};

export function defaultMedia(ref: string): MediaDetails | null {
	if (ref.trim() === "") {
		return null;
	}
	const url = /^(https?:)?\/\//.test(ref) || ref.startsWith("/") ? ref : `/storage/${ref}`;
	const ext = /\.([a-z0-9]+)(?:\?|#|$)/i.exec(ref)?.[1]?.toLowerCase();
	return { url, mime: ext === undefined ? null : (MIME[ext] ?? null) };
}
