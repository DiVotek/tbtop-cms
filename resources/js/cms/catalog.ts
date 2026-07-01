import { defineCatalog } from "@json-render/core";
import { schema } from "@json-render/react/schema";
import { z } from "zod";

export const heroProps = z.object({
    title: z.string(),
    subtitle: z.string().nullable(),
    image: z.string().nullable(),
    cta: z.object({ label: z.string(), href: z.string() }).nullable(),
});

export const contactFormProps = z.object({
    heading: z.string(),
    submitLabel: z.string(),
    successMessage: z.string(),
    action: z.string(),
    fields: z.array(
        z.object({
            name: z.string(),
            label: z.string(),
            type: z.string(),
            required: z.boolean(),
        }),
    ),
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
        ContactForm: {
            props: contactFormProps,
            description: "Anonymous contact form that posts to the CMS submit endpoint.",
        },
    },
    actions: {},
});
