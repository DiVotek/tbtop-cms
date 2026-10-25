import type { BlockProps } from "../registry";

export function StatsStrip({ props }: BlockProps<"StatsStrip">) {
	return (
		<section className="bg-ocean-900 py-10 text-white" data-testid="stats-strip">
			<div className="mx-auto flex max-w-6xl flex-wrap justify-center gap-10 px-4 md:px-6">
				{props.items.map((item) => (
					<div key={item.label} className="text-center">
						<p className="text-4xl font-bold tabular-nums">{item.value}</p>
						<p className="mt-1 text-sm uppercase tracking-wide text-ocean-100/80">{item.label}</p>
					</div>
				))}
			</div>
		</section>
	);
}
