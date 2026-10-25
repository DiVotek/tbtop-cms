import type { BlockProps } from "../registry";

export function SiteNav({ props }: BlockProps<"SiteNav">) {
	return (
		<header className="sticky top-0 z-50 border-b border-ocean-900/10 bg-white/95 backdrop-blur" data-testid="site-nav">
			<div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 md:px-6">
				<a href="/" className="group flex flex-col">
					<span className="text-lg font-semibold tracking-tight text-ocean-900 group-hover:text-ocean-600">{props.siteName}</span>
					{props.tagline ? <span className="text-xs text-ocean-800/70">{props.tagline}</span> : null}
				</a>
				<nav className="hidden items-center gap-1 md:flex" aria-label="Main">
					{props.links.map((link) => (
						<a key={link.href} href={link.href} className="rounded-lg px-3 py-2 text-sm font-medium text-ocean-900/80 hover:bg-ocean-100 hover:text-ocean-900">
							{link.label}
						</a>
					))}
				</nav>
				<details className="relative md:hidden">
					<summary className="cursor-pointer list-none rounded-lg border border-ocean-900/15 px-3 py-2 text-sm font-medium">Menu</summary>
					<nav className="absolute right-0 mt-2 min-w-48 rounded-xl border border-ocean-900/10 bg-white p-2 shadow-lg" aria-label="Mobile">
						{props.links.map((link) => (
							<a key={link.href} href={link.href} className="block rounded-lg px-3 py-2 text-sm hover:bg-ocean-100">
								{link.label}
							</a>
						))}
					</nav>
				</details>
			</div>
		</header>
	);
}
