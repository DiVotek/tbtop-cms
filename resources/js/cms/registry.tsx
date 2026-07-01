import { defineRegistry } from "@json-render/react";
import { ContactForm } from "./blocks/ContactForm";
import { Hero } from "./blocks/Hero";
import { catalog } from "./catalog";

export const { registry } = defineRegistry(catalog, {
    components: {
        Fragment: ({ children }) => <>{children}</>,
        Hero,
        ContactForm,
    },
});
