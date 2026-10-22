import { createRootRoute, HeadContent, Outlet, Scripts, useRouterState } from "@tanstack/react-router";
import type { PageProps } from "@tbtop/cms";

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
	return (
		<html lang={locale}>
			<head>
				<HeadContent />
			</head>
			<body>
				<Outlet />
				<Scripts />
			</body>
		</html>
	);
}
