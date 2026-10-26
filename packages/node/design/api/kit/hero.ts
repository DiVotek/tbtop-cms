import { cmsBlocks } from "../../../src/blocks/catalog";
import type { BlockDef } from "../v2-config/define";

/**
 * The built-in Hero as one client-safe definition (meta + form + resolve), as
 * the package would export it for V2–V4 (`import { hero } from "@tbtop/cms/blocks"`).
 * Today its form/resolve sit in `cmsBlockImpls`, in the server entry.
 */
export const hero: BlockDef<typeof cmsBlocks.Hero.props> = {
	...cmsBlocks.Hero,
	form: (s) => [
		s.text("title").label("Title").translatable().required(),
		s.textarea("subtitle").label("Subtitle").translatable(),
		s.text("image").label("Image URL"),
		s.text("ctaLabel").label("CTA label").translatable(),
		s.text("ctaHref").label("CTA link"),
	],
	resolve: (input) => ({
		title: typeof input.title === "string" ? input.title : "",
		subtitle: typeof input.subtitle === "string" && input.subtitle !== "" ? input.subtitle : null,
		image: typeof input.image === "string" && input.image !== "" ? input.image : null,
		cta: typeof input.ctaLabel === "string" && typeof input.ctaHref === "string" && input.ctaLabel && input.ctaHref ? { label: input.ctaLabel, href: input.ctaHref } : null,
	}),
};
