import type { BlockProps } from "../registry";

export function CoopBenefits({ props }: BlockProps<"CoopBenefits">) {
	return (
		<section className="mx-auto max-w-3xl px-4 py-10 md:px-6" data-testid="coop-benefits">
			{props.heading ? <h2 className="mb-4 text-2xl font-semibold text-ocean-900">{props.heading}</h2> : null}
			<ul className="mb-6 list-disc space-y-2 pl-5 text-ocean-900/90">
				{props.items.map((item) => (
					<li key={item}>{item}</li>
				))}
			</ul>
			{props.body ? <p className="leading-relaxed text-ocean-900/85">{props.body}</p> : null}
		</section>
	);
}
