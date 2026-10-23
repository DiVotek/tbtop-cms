import "./admin.css";
import { createInertiaApp } from "@inertiajs/react";
import { linkFieldClient } from "@tbtop/cms/admin-client";
import { AdminErrorPage, AdminPage } from "@tbtop/inertia-admin";
import { createRoot } from "react-dom/client";

// The CMS link field's client (the `link` kind); importing it registers it.
void linkFieldClient;

void createInertiaApp({
	title: (title) => (title ? `${title} · tbtop cms` : "tbtop cms"),
	resolve: (name) => (name === "admin/error" ? AdminErrorPage : AdminPage),
	setup({ el, App, props }) {
		if (el !== null) {
			createRoot(el).render(<App {...props} />);
		}
	},
});
