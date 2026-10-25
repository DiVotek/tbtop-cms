import { useEffect, useState } from "react";

export function ThemeToggle() {
	const [dark, setDark] = useState(false);
	useEffect(() => {
		const stored = localStorage.getItem("lumen-theme");
		const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
		const next = stored === "dark" || (stored === null && prefersDark);
		setDark(next);
		document.documentElement.classList.toggle("dark", next);
	}, []);
	return (
		<button
			type="button"
			data-testid="theme-toggle"
			className="rounded-full border border-lumen-line px-3 py-1 text-xs font-medium"
			onClick={() => {
				setDark((value) => {
					const next = !value;
					document.documentElement.classList.toggle("dark", next);
					localStorage.setItem("lumen-theme", next ? "dark" : "light");
					return next;
				});
			}}
		>
			{dark ? "Light" : "Dark"}
		</button>
	);
}
