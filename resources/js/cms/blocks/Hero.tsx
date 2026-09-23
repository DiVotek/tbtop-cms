import type { HeroProps } from "../blocks.generated";

export function Hero({ props }: { props: HeroProps }) {
    return (
        <section data-testid="hero" className="cms-hero">
            <h1>{props.title}</h1>
            {props.subtitle ? <p>{props.subtitle}</p> : null}
            {props.image ? <img src={props.image} alt="" /> : null}
            {props.cta ? (
                <a href={props.cta.href}>{props.cta.label}</a>
            ) : null}
        </section>
    );
}
