import { defineRegistry } from "@json-render/react";
import type { PropsOf } from "@tbtop/cms/catalog";
import { cmsCatalog, cmsComponents, useCmsForm } from "@tbtop/cms/react";
import { Link } from "@tanstack/react-router";
import { catalog as blocks } from "./catalog";

export const catalog = cmsCatalog(blocks);

function SiteNav() {
	const links = [
		{ href: "/work", en: "Work", de: "Arbeit" },
		{ href: "/journal", en: "Journal", de: "Journal" },
		{ href: "/about", en: "About", de: "Über uns" },
		{ href: "/get-in-touch", en: "Contact", de: "Kontakt" },
	];
	return (
		<header data-testid="site-nav" className="border-b border-lumen-line bg-lumen-bg/90 backdrop-blur-md">
			<div className="lumen-container flex h-16 items-center justify-between gap-4">
				<Link to="/$" params={{ _splat: "" }} className="font-display text-xl font-semibold tracking-tight">
					Lumen
				</Link>
				<nav aria-label="Primary" className="hidden items-center gap-6 text-sm font-medium sm:flex">
					{links.map((link) => (
						<CmsLink key={link.href} href={link.href}>
							{link.en}
						</CmsLink>
					))}
				</nav>
			</div>
		</header>
	);
}

function SiteFooter({ props: _props }: { props: PropsOf<typeof blocks, "SiteFooter"> }) {
	return (
		<footer data-testid="site-footer" className="border-t border-lumen-line py-12 text-sm text-lumen-muted">
			<div className="lumen-container grid gap-8 sm:grid-cols-2">
				<div>
					<p className="font-display text-lg text-lumen-ink">Lumen Studio</p>
					<p className="mt-2 max-w-sm">Calm digital products from Berlin — design systems, apps, and launch sites.</p>
				</div>
				<div className="flex flex-col gap-2 sm:items-end">
					<a href="mailto:hello@lumen.studio.test" className="hover:text-lumen-accent">
						hello@lumen.studio.test
					</a>
					<a href="/feed.xml" className="hover:text-lumen-accent">
						RSS
					</a>
				</div>
			</div>
		</footer>
	);
}

function StudioHero({ props }: { props: PropsOf<typeof blocks, "StudioHero"> }) {
	return (
		<section data-testid="studio-hero" className="lumen-container py-16 sm:py-24">
			{props.eyebrow ? <p className="text-sm font-semibold uppercase tracking-widest text-lumen-accent">{props.eyebrow}</p> : null}
			<h1 className="font-display mt-4 max-w-3xl text-4xl font-semibold leading-tight sm:text-5xl">{props.title}</h1>
			{props.subtitle ? <p className="mt-6 max-w-2xl text-lg text-lumen-muted">{props.subtitle}</p> : null}
			<div className="mt-8 flex flex-wrap gap-3">
				{props.cta ? <Cta href={props.cta.href} primary label={props.cta.label} /> : null}
				{props.secondary ? <Cta href={props.secondary.href} label={props.secondary.label} /> : null}
			</div>
		</section>
	);
}

function ServiceGrid({ props }: { props: PropsOf<typeof blocks, "ServiceGrid"> }) {
	return (
		<section className="lumen-container pb-16">
			{props.heading ? <h2 className="font-display mb-8 text-2xl font-semibold">{props.heading}</h2> : null}
			<div className="grid gap-6 md:grid-cols-3">
				{props.items.map((item) => (
					<article key={item.title} className="rounded-2xl border border-lumen-line bg-lumen-card p-6 shadow-sm">
						<h3 className="font-display text-lg font-semibold">{item.title}</h3>
						<p className="mt-3 text-sm text-lumen-muted">{item.body}</p>
					</article>
				))}
			</div>
		</section>
	);
}

function StatBand({ props }: { props: PropsOf<typeof blocks, "StatBand"> }) {
	return (
		<section className="border-y border-lumen-line bg-lumen-card/60 py-10">
			<div className="lumen-container grid gap-8 sm:grid-cols-3">
				{props.stats.map((stat) => (
					<div key={stat.label}>
						<p className="font-display text-3xl font-semibold">{stat.value}</p>
						<p className="mt-1 text-sm text-lumen-muted">{stat.label}</p>
					</div>
				))}
			</div>
		</section>
	);
}

function TeamStrip({ props }: { props: PropsOf<typeof blocks, "TeamStrip"> }) {
	return (
		<section className="lumen-container py-12">
			{props.heading ? <h2 className="font-display mb-6 text-2xl font-semibold">{props.heading}</h2> : null}
			<ul className="grid gap-4 sm:grid-cols-3">
				{props.members.map((member) => (
					<li key={member.name} className="rounded-xl border border-lumen-line p-4">
						<p className="font-medium">{member.name}</p>
						<p className="text-sm text-lumen-muted">{member.role}</p>
					</li>
				))}
			</ul>
		</section>
	);
}

function CaseStudyList({ props }: { props: PropsOf<typeof blocks, "CaseStudyList"> }) {
	return (
		<section className="lumen-container grid gap-4 py-8">
			{props.cases.map((item) => (
				<CmsLink key={item.title} href={item.href} className="group flex items-center justify-between rounded-2xl border border-lumen-line bg-lumen-card p-6 transition hover:border-lumen-accent">
					<div>
						<p className="text-xs font-semibold uppercase tracking-wide text-lumen-accent">{item.tag}</p>
						<p className="font-display mt-1 text-xl font-semibold group-hover:text-lumen-accent">{item.title}</p>
					</div>
					<span aria-hidden>→</span>
				</CmsLink>
			))}
		</section>
	);
}

function PullQuote({ props }: { props: PropsOf<typeof blocks, "PullQuote"> }) {
	return (
		<blockquote className="my-10 border-l-4 border-lumen-accent pl-6">
			{props.quote ? <p className="font-display text-2xl leading-snug">{props.quote}</p> : null}
			{props.attribution ? <footer className="mt-3 text-sm text-lumen-muted">— {props.attribution}</footer> : null}
		</blockquote>
	);
}

function Newsletter({ props }: { props: PropsOf<typeof blocks, "Newsletter"> }) {
	const form = useCmsForm(props.action);
	if (form.status === "sent") {
		return (
			<section data-testid="newsletter-form" className="rounded-2xl border border-lumen-line bg-lumen-accent-soft/40 p-6">
				<p role="status">{props.successMessage}</p>
			</section>
		);
	}
	return (
		<section data-testid="newsletter-form" className="rounded-2xl border border-lumen-line bg-lumen-card p-6">
			{props.heading ? <h2 className="font-display text-lg font-semibold">{props.heading}</h2> : null}
			{props.description ? <p className="mt-2 text-sm text-lumen-muted">{props.description}</p> : null}
			<form className="mt-4 flex flex-col gap-3 sm:flex-row" {...form.formProps}>
				<label className="sr-only" htmlFor="newsletter-email">
					Email
				</label>
				<input id="newsletter-email" name="email" type="email" required placeholder="you@company.com" className="min-w-0 flex-1 rounded-lg border border-lumen-line bg-lumen-bg px-3 py-2" />
				{props.honeypotField ? <input type="text" name={props.honeypotField} tabIndex={-1} autoComplete="off" className="hidden" /> : null}
				<button type="submit" disabled={form.status === "sending"} className="rounded-lg bg-lumen-accent px-4 py-2 font-medium text-white">
					{props.submitLabel}
				</button>
			</form>
			{form.errors.email?.map((error) => (
				<p key={error} role="alert" className="mt-2 text-sm text-red-600">
					{error}
				</p>
			))}
		</section>
	);
}

function Cta({ href, label, primary = false }: { href: string; label: string; primary?: boolean }) {
	const className = primary
		? "inline-flex rounded-full bg-lumen-accent px-5 py-2.5 text-sm font-semibold text-white shadow-sm"
		: "inline-flex rounded-full border border-lumen-line px-5 py-2.5 text-sm font-semibold";
	return (
		<CmsLink href={href} className={className}>
			{label}
		</CmsLink>
	);
}

function CmsLink({ href, children, className }: { href: string; children: React.ReactNode; className?: string }) {
	const path = new URL(href, "http://site").pathname;
	return (
		<Link to="/$" params={{ _splat: path === "/" ? "" : path.slice(1) }} className={className}>
			{children}
		</Link>
	);
}

export const { registry } = defineRegistry(catalog, {
	components: {
		...cmsComponents,
		ContactForm: (p) => <StyledContactForm {...p} />,
		SiteNav,
		SiteFooter,
		StudioHero,
		ServiceGrid,
		StatBand,
		TeamStrip,
		CaseStudyList,
		PullQuote,
		Newsletter,
	},
});

function StyledContactForm({ props }: { props: PropsOf<typeof blocks, "ContactForm"> }) {
	const form = useCmsForm(props.action);
	if (form.status === "sent") {
		return (
			<section data-testid="contact-form" className="lumen-container max-w-xl py-8">
				<p role="status" className="rounded-xl bg-lumen-accent-soft/50 p-4">
					{props.successMessage}
				</p>
			</section>
		);
	}
	return (
		<section data-testid="contact-form" className="lumen-container max-w-xl py-8">
			{props.heading ? <h2 className="font-display mb-6 text-2xl font-semibold">{props.heading}</h2> : null}
			<form className="grid gap-4" {...form.formProps}>
				{props.fields.map((field) => (
					<label key={field.name} htmlFor={`contact-${field.name}`} className="grid gap-1 text-sm">
						<span>{field.label}</span>
						{field.type === "textarea" ? (
							<textarea id={`contact-${field.name}`} name={field.name} required={field.required} rows={5} className="rounded-lg border border-lumen-line bg-lumen-bg px-3 py-2" />
						) : (
							<input id={`contact-${field.name}`} type={field.type} name={field.name} required={field.required} className="rounded-lg border border-lumen-line bg-lumen-bg px-3 py-2" />
						)}
					</label>
				))}
				<button type="submit" className="w-fit rounded-lg bg-lumen-accent px-5 py-2.5 font-semibold text-white">
					{props.submitLabel}
				</button>
			</form>
		</section>
	);
}
