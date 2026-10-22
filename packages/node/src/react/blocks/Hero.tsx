import type { PropsOf } from "../../blocks/catalog";
import type { cmsBlocks } from "../../blocks/catalog";

/** The built-in Hero, unstyled: `cms-hero` and `data-testid="hero"` are the hooks. */
export function Hero({ props }: { props: PropsOf<typeof cmsBlocks, "Hero"> }) {
	return (
		<section data-testid="hero" className="cms-hero">
			<h2>{props.title}</h2>
			{props.subtitle ? <p>{props.subtitle}</p> : null}
			{props.image ? <img src={props.image} alt="" /> : null}
			{props.cta ? <a href={props.cta.href}>{props.cta.label}</a> : null}
		</section>
	);
}
