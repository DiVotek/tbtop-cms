import type { NotificationStore } from "@tbtop/admin";
import type { Kysely } from "kysely";
import type { BlockRegistry } from "./blocks/registry";
import type { CmsDatabase } from "./db/schema";
import type { PageTypeDef } from "./content/pageTypes";
import type { SubmissionEvent } from "./forms/submit";
import type { Channel, DeliveryEvent, MailTransport, NotificationType } from "./notifications/types";
import type { SchemaTypeDef } from "./seo/schemaTypes";

/** What a media reference (an upload path or URL) resolves to; width/height/alt feed og:image. */
export type MediaDetails = { url: string; alt?: string | null; mime?: string | null; width?: number | null; height?: number | null };

export type CmsConfig = {
	/** The host's Kysely instance over a database the package migrations ran on. */
	db: Kysely<CmsDatabase>;
	/** The block kinds: `implementBlocks({ ...cmsBlocks, ...mine }, { ...cmsBlockImpls, ...myImpls })`. */
	blocks: BlockRegistry;
	/** Extra page types next to the built-in page, category and article. */
	pageTypes?: PageTypeDef[];
	/** Extra JSON-LD schema types next to the built-in WebPage family, Article family and CollectionPage. */
	schemaTypes?: SchemaTypeDef[];
	/** The public site origin (`https://example.com`); default: the request's origin. */
	url?: string;
	/** A media reference to its public URL; default: absolute URLs and `/…` paths as is, other paths under `/storage/`. */
	media?: (ref: string) => MediaDetails | null;
	/** Settings keys holding secrets; never sent to the frontend. */
	secretSettings?: string[];
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
	 * Throw on a broken block row instead of skipping it (`tbtop-cms.blocks.strict`).
	 * Unset: off, except that resolved props failing the catalog's zod schema
	 * throw in development (on Node/Bun a NODE_ENV other than `production`, unset included; never on Workers).
	 */
	strictBlocks?: boolean;
	/** Can this request see drafts (preview)? Default: never. */
	canPreview?: (request: Request) => boolean | Promise<boolean>;
	/** Extra frontend strings per locale, over the package's own. */
	strings?: Record<string, Record<string, string>>;
	debug?: boolean;
	forms?: FormsConfig;
	notifications?: NotificationsConfig;
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
