/** The rendering shape a channel consumes: full HTML, plain text, or a short message (chat, bell). */
export type Format = "html" | "text" | "short";

export type Audience = "staff" | "customer";

/**
 * Who a notification is for, in the addresses a channel needs. A recipient
 * carries only what it has: a Telegram chat has no email, a mail address no user.
 */
export type Recipient = {
	audience: Audience;
	locale: string;
	email?: string;
	/** A chat id for Telegram, a phone number for an SMS channel. */
	phone?: string;
	/** A host user, for the admin bell. */
	user?: unknown;
};

/** A template's output: a plain-text subject, a body in the channel's format, the payload it came from. */
export type Rendered = { subject: string; body: string; data: Record<string, unknown> };

/** What a built-in template gets besides the payload: the package strings in the recipient's locale. */
export type TemplateContext = { locale: string; t: (key: string, params?: Record<string, string | number>) => string };

/** A built-in template: a typed function of the payload. A merchant override in Liquid takes its place. */
export type Template<P> = (payload: P, ctx: TemplateContext) => { subject: string; body: string };

/**
 * A notification type (TypeDefinition.php): its payload's templates per
 * format, who may receive it and the channels on by default. The merchant
 * turns channels on or off per type in the notification settings.
 */
export type NotificationType<P = Record<string, unknown>> = {
	key: string;
	label: string;
	/** The settings section the type is listed under: `forms`, `orders`. */
	group: string;
	audiences: Audience[];
	defaultChannels: string[];
	templates: Partial<Record<Format, Template<P>>>;
	/** The variables a Liquid override can use, for the template editor. */
	variables?: string[];
};

/** A delivery channel (Contracts/Channel.php). The host provides mail; Telegram and the bell are built in. */
export type Channel = {
	key: string;
	label: string;
	format: Format;
	/** Whether the recipient has the address this channel needs. */
	supports(recipient: Recipient): boolean;
	send(rendered: Rendered, recipient: Recipient, type: string): Promise<void>;
};

/** One attempted (recipient, channel) pair and how it went. */
export type Delivery = {
	type: string;
	channel: string;
	recipient: string;
	status: "sent" | "failed";
	/** `render` when the template failed, `send` when the channel did. */
	stage: "render" | "send";
	error: string | null;
};

/** What `notifications.onDelivery` gets: the delivery and the recipient it went to. */
export type DeliveryEvent = Delivery & { to: Recipient };

/** The message a host mail transport sends: nodemailer, Resend, Cloudflare Email, SES. */
export type MailMessage = { to: string; from: string | null; subject: string; html: string; text: string };

export type MailTransport = { send(message: MailMessage): Promise<void> };
