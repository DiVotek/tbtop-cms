import type { BlockProps } from "../registry";

export function SiteFooter({ props }: BlockProps<"SiteFooter">) {
	return (
		<footer className="mt-16 border-t border-ocean-900/10 bg-ocean-950 text-ocean-100" data-testid="site-footer">
			<div className="mx-auto grid max-w-6xl gap-8 px-4 py-10 md:grid-cols-3 md:px-6">
				<div>
					<p className="text-lg font-semibold text-white">{props.siteName}</p>
					{props.note ? <p className="mt-2 text-sm text-ocean-100/80">{props.note}</p> : null}
				</div>
				<div>
					<p className="text-sm font-medium uppercase tracking-wide text-ocean-100/60">Контакти</p>
					{props.phone ? (
						<a href={`tel:${props.phone.replace(/\s/g, "")}`} className="mt-2 block text-white hover:underline">
							{props.phone}
						</a>
					) : null}
					{props.email ? (
						<a href={`mailto:${props.email}`} className="mt-1 block text-white/90 hover:underline">
							{props.email}
						</a>
					) : null}
				</div>
				<nav aria-label="Footer">
					<ul className="space-y-2 text-sm">
						{props.links.map((link) => (
							<li key={link.href}>
								<a href={link.href} className="text-ocean-100/90 hover:text-white">
									{link.label}
								</a>
							</li>
						))}
					</ul>
				</nav>
			</div>
			<div className="border-t border-white/10 py-4 text-center text-xs text-ocean-100/50">© {new Date().getFullYear()} {props.siteName}</div>
		</footer>
	);
}
