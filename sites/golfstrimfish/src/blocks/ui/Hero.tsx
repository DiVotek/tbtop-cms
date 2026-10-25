import type { PropsOf } from "@tbtop/cms/catalog";
import type { cmsBlocks } from "@tbtop/cms/catalog";

export function Hero({ props }: { props: PropsOf<typeof cmsBlocks, "Hero"> }) {
	const href = props.cta?.href ?? null;
	return (
		<section data-testid="hero" className="relative overflow-hidden bg-gradient-to-br from-ocean-900 via-ocean-800 to-ocean-950 text-white">
			<div className="mx-auto max-w-6xl px-4 py-16 md:px-6 md:py-24">
				<h2 className="max-w-3xl text-3xl font-semibold leading-tight tracking-tight md:text-5xl">{props.title}</h2>
				{props.subtitle ? <p className="mt-4 max-w-2xl text-lg text-ocean-100/90">{props.subtitle}</p> : null}
				{props.cta && href !== null ? (
					<a href={href} className="mt-8 inline-flex rounded-full bg-white px-6 py-3 text-sm font-semibold text-ocean-900 shadow transition hover:bg-ocean-100">
						{props.cta.label}
					</a>
				) : null}
			</div>
		</section>
	);
}
