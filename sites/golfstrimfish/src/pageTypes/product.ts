import { definePageType } from "@tbtop/cms";
import { z } from "zod";

/** Fish catalog item: a subtype of article so it can live under category pages. */
export const productType = definePageType({
	key: "product",
	base: "article",
	label: "Product",
	fields: z.object({
		price: z.number().nullable(),
		unit: z.string().nullable(),
	}),
	form: (s) => [s.number("price").label("Price (UAH)").required(), s.text("unit").label("Unit").placeholder("1 kg")],
});
