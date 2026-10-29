import { createRouter } from "@tanstack/react-router";
import { routeTree } from "./routeTree.gen";

export function getRouter() {
	return createRouter({ routeTree, scrollRestoration: true, defaultNotFoundComponent: NotFound });
}

function NotFound() {
	return <h1 data-testid="not-found">Page not found</h1>;
}
