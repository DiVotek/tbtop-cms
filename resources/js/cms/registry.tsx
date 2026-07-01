import { defineRegistry } from "@json-render/react";
import { Hero } from "./blocks/Hero";
import { catalog } from "./catalog";

export const { registry } = defineRegistry(catalog, {
    components: {
        Fragment: ({ children }) => <>{children}</>,
        Hero,
    },
});
