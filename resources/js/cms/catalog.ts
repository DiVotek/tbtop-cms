import { defineCatalog } from "@json-render/core";
import { schema } from "@json-render/react/schema";
import { z } from "zod";

export const heroProps = z.object({
    title: z.string(),
    subtitle: z.string().nullable(),
    image: z.string().nullable(),
    cta: z.object({ label: z.string(), href: z.string() }).nullable(),
});

export const catalog = defineCatalog(schema, {
    components: {
        Fragment: {
            props: z.object({}),
            slots: ["default"],
            description: "Layout passthrough that renders its child blocks in order.",
        },
        Hero: {
            props: heroProps,
            description: "Hero section with heading, optional subtitle, image and CTA.",
        },
    },
    actions: {},
});
