import "./admin.css";
import { createInertiaApp } from "@inertiajs/react";
import { linkFieldClient } from "@tbtop/cms/admin-client";
import { AdminErrorPage, AdminPage } from "@tbtop/inertia-admin";
import { createRoot } from "react-dom/client";

void linkFieldClient;

void createInertiaApp({
	title: (title) => (title ? `${title} · Lumen Studio` : "Lumen Studio"),
	resolve: (name) => (name === "admin/error" ? AdminErrorPage : AdminPage),
	setup({ el, App, props }) {
		if (el !== null) {
			createRoot(el).render(<App {...props} />);
		}
	},
});
