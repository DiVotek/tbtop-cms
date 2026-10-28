import "@tbtop/inertia-admin/styles.css";

import { createInertiaApp, type ResolvedComponent } from "@inertiajs/react";
import { AdminPage } from "@tbtop/inertia-admin";
import "@cms/admin/blocks";
import { createRoot } from "react-dom/client";

createInertiaApp({
    resolve: (name) => {
        if (name === "admin/page") {
            return AdminPage as ResolvedComponent;
        }

        const pages = import.meta.glob<{ default: ResolvedComponent }>(
            "./pages/**/*.tsx",
        );

        return pages[`./pages/${name}.tsx`]().then((module) => module.default);
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
