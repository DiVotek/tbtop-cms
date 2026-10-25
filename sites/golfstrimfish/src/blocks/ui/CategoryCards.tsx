import type { BlockProps } from "../registry";

export function CategoryCards({ props }: BlockProps<"CategoryCards">) {
	return (
		<section className="mx-auto max-w-6xl px-4 py-12 md:px-6" data-testid="category-cards">
			{props.heading ? <h2 className="mb-8 text-center text-2xl font-semibold text-ocean-900 md:text-3xl">{props.heading}</h2> : null}
			<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				{props.items.map((item) => (
					<a
						key={item.title}
						href={item.link?.href ?? "#"}
						className="group overflow-hidden rounded-2xl border border-ocean-900/10 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
					>
						<div className="aspect-[4/3] bg-gradient-to-br from-ocean-600/20 to-ocean-900/30">
							{item.image ? <img src={item.image} alt="" className="h-full w-full object-cover opacity-90 transition group-hover:scale-105" /> : null}
						</div>
						<div className="p-4">
							<h3 className="font-semibold text-ocean-900">{item.title}</h3>
							<span className="mt-1 inline-block text-sm text-ocean-600">Переглянути →</span>
						</div>
					</a>
				))}
			</div>
		</section>
	);
}
