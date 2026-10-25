import type { BlockProps } from "../registry";

export function ProductGrid({ props }: BlockProps<"ProductGrid">) {
	return (
		<section className="mx-auto max-w-6xl px-4 py-10 md:px-6" data-testid="product-grid">
			{props.heading ? <h2 className="mb-6 text-2xl font-semibold text-ocean-900">{props.heading}</h2> : null}
			<ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
				{props.products.map((product) => (
					<li key={product.id}>
						<a href={product.url} className="flex h-full flex-col rounded-xl border border-ocean-900/10 bg-white p-5 shadow-sm transition hover:border-ocean-600/40 hover:shadow-md">
							<h3 className="font-medium text-ocean-900">{product.name}</h3>
							<span className="mt-auto pt-4 text-sm font-medium text-ocean-600">Детальніше →</span>
						</a>
					</li>
				))}
			</ul>
		</section>
	);
}
