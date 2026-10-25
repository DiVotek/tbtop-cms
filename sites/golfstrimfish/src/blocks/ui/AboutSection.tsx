import type { BlockProps } from "../registry";

export function AboutSection({ props }: BlockProps<"AboutSection">) {
	return (
		<section className="mx-auto max-w-3xl px-4 py-12 md:px-6" data-testid="about-section">
			{props.heading ? <h2 className="mb-6 text-2xl font-semibold text-ocean-900">{props.heading}</h2> : null}
			<div className="cms-prose whitespace-pre-line text-ocean-900/90">{props.body}</div>
		</section>
	);
}
