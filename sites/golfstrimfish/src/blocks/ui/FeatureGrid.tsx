import type { BlockProps } from "../registry";

export function FeatureGrid({ props }: BlockProps<"FeatureGrid">) {
	return (
		<section className="mx-auto max-w-6xl px-4 py-12 md:px-6" data-testid="feature-grid">
			{props.heading ? <h2 className="mb-8 text-2xl font-semibold text-ocean-900">{props.heading}</h2> : null}
			<ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
				{props.items.map((item) => (
					<li key={item.title} className="rounded-xl border border-ocean-900/10 bg-white p-5 shadow-sm">
						<h3 className="font-semibold text-ocean-900">{item.title}</h3>
						{item.text ? <p className="mt-2 text-sm leading-relaxed text-ocean-900/75">{item.text}</p> : null}
					</li>
				))}
			</ul>
		</section>
	);
}
