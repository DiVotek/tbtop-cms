import { createRootRoute, HeadContent, Outlet, Scripts, useRouterState } from "@tanstack/react-router";
import type { PageProps } from "@tbtop/cms";
import { GtmNoscript } from "@tbtop/cms/react";
import "../styles/app.css";

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
		<html lang={locale} suppressHydrationWarning>
			<head>
				<HeadContent />
				<script
					dangerouslySetInnerHTML={{
						__html: `(function(){try{var t=localStorage.getItem('lumen-theme');var d=t==='dark'||(t!=='light'&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.classList.toggle('dark',d);}catch(e){}})();`,
					}}
				/>
			</head>
			<body className="antialiased">
				<GtmNoscript id={gtmContainerId} />
				<Outlet />
				<Scripts />
			</body>
		</html>
	);
}
