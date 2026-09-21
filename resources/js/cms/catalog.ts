import { defineCatalog } from "@json-render/core";
import { schema } from "@json-render/react/schema";
import { z } from "zod";
import { contactFormProps, heroProps } from "./blocks.generated";

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
        ContactForm: {
            props: contactFormProps,
            description: "Anonymous contact form that posts to the CMS submit endpoint.",
        },
    },
    actions: {},
});
