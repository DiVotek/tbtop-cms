import type { BlockProps } from "../registry";

export function ProcessSteps({ props }: BlockProps<"ProcessSteps">) {
	return (
		<section className="bg-white py-12" data-testid="process-steps">
			<div className="mx-auto max-w-6xl px-4 md:px-6">
				{props.heading ? <h2 className="mb-10 text-center text-2xl font-semibold text-ocean-900">{props.heading}</h2> : null}
				<ol className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
					{props.steps.map((step, index) => (
						<li key={step.title} className="relative rounded-2xl border border-ocean-900/10 bg-sand-50 p-6">
							<span className="absolute -left-2 -top-2 flex h-8 w-8 items-center justify-center rounded-full bg-ocean-600 text-sm font-bold text-white">{index + 1}</span>
							<h3 className="font-semibold text-ocean-900">{step.title}</h3>
							<p className="mt-2 text-sm leading-relaxed text-ocean-900/80">{step.text}</p>
						</li>
					))}
				</ol>
			</div>
		</section>
	);
}
