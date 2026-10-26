import { promoProps } from "../shared/reference";
import { defineBlock } from "./define";

// One file per block in a real site; the browser imports these too (props + copy), the form never runs there.

export const Promo = defineBlock({
	label: "Promo",
	category: "content",
	props: promoProps,
	form: (s, f) => [s.text("title").label("Title").translatable(), f.link("cta").label("Button")],
});
