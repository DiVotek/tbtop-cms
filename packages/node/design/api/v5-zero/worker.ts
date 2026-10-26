import { ADMIN, fixture, html } from "../shared/reference";
import { cloudflare } from "./package";
import { site } from "./site";

// V5 · Q16: the whole Worker.
export default cloudflare(site, { seed: fixture, admin: ADMIN, render: (props) => html(props, props.page.type === "service" ? `${props.page.fields.price} UAH · ${props.page.fields.duration}` : props.page.type === "page" ? (props.page.fields.tagline ?? "") : "") });
