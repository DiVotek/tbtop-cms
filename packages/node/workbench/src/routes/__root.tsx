import { createRootRoute, HeadContent, Outlet, Scripts, useRouterState } from "@tanstack/react-router";
import type { PageProps } from "@tbtop/cms";
import { GtmNoscript } from "@tbtop/cms/react";

export const Route = createRootRoute({
	head: () => ({
		meta: [{ charSet: "utf-8" }, { name: "viewport", content: "width=device-width, initial-scale=1" }],
	}),
	component: Root,
});

function Root() {
	const locale = useRouterState({
		select: (state) => (state.matches.at(-1)?.loaderData as PageProps | undefined)?.cms.locale ?? "en",
	});
	const gtmContainerId = useRouterState({
		select: (state) => (state.matches.at(-1)?.loaderData as PageProps | undefined)?.seo.gtmContainerId ?? null,
	});
	return (
		<html lang={locale}>
			<head>
				<HeadContent />
			</head>
			<body>
				{/* GTM's loader script is in the head (seoHead); its noscript half goes right after <body>. */}
				<GtmNoscript id={gtmContainerId} />
				<Outlet />
				<Scripts />
			</body>
		</html>
	);
}
