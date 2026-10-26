// Shared with the browser bundle: zod and copy only.
import { cmsBlocks, defineBlocks } from "../../../src/blocks/catalog";
import { promoProps } from "../shared/reference";

export const catalog = defineBlocks({
	Hero: cmsBlocks.Hero,
	Promo: { label: "Promo", category: "content", props: promoProps },
});
