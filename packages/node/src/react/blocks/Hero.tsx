import type { PropsOf } from "../../blocks/catalog";
import type { cmsBlocks } from "../../blocks/catalog";
import { safeHref } from "../../content/safeHref";

/** The built-in Hero, unstyled: `cms-hero` and `data-testid="hero"` are the hooks. */
export function Hero({ props }: { props: PropsOf<typeof cmsBlocks, "Hero"> }) {
	// Props a host builds itself skip the resolver and the catalog: the link is checked here too.
	const href = props.cta ? safeHref(props.cta.href) : null;
	return (
		<section data-testid="hero" className="cms-hero">
			<h2>{props.title}</h2>
			{props.subtitle ? <p>{props.subtitle}</p> : null}
			{props.image ? <img src={props.image} alt="" /> : null}
			{props.cta && href !== null ? <a href={href}>{props.cta.label}</a> : null}
		</section>
	);
}
