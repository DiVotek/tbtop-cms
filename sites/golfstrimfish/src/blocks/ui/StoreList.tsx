import type { BlockProps } from "../registry";

export function StoreList({ props }: BlockProps<"StoreList">) {
	return (
		<section className="mx-auto max-w-6xl px-4 py-10 md:px-6" data-testid="store-list">
			{props.heading ? <h2 className="mb-6 text-2xl font-semibold text-ocean-900">{props.heading}</h2> : null}
			<ul className="grid gap-3 sm:grid-cols-2">
				{props.locations.map((loc) => (
					<li key={loc.address} className="rounded-lg border border-ocean-900/10 bg-white px-4 py-3 text-sm">
						{loc.city ? <span className="font-medium text-ocean-900">{loc.city}: </span> : null}
						<span className="text-ocean-900/85">{loc.address}</span>
					</li>
				))}
			</ul>
		</section>
	);
}
