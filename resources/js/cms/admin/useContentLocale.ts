import { useEffect, useState } from "react";

/**
 * The content locale tab the editor is currently on.
 *
 * The admin client keeps this in a React context, but does not export the hook
 * that reads it, so a package block cannot subscribe the ordinary way. It does
 * export the markup: the tab strip is `[data-testid="content-locale-bar"]` and
 * each tab is `locale-tab-{code}`, with the active one carrying
 * `aria-selected="true"` (see contentLocaleBar.tsx). Those attributes are what
 * the client's own tests assert against, so they are as stable as the context
 * would be — this reads the same state through the DOM instead of through
 * React.
 *
 * Returns null when the form has no locale bar at all: a single-locale install
 * renders none (the bar returns null below two locales), and callers fall back
 * to the default locale.
 */
const BAR = '[data-testid="content-locale-bar"]';
const TAB_PREFIX = "locale-tab-";

function readActive(): string | null {
	const bar = document.querySelector(BAR);
	if (bar === null) {
		return null;
	}

	const active = bar.querySelector(`[data-testid^="${TAB_PREFIX}"][aria-selected="true"]`);
	const testId = active?.getAttribute("data-testid");

	return testId ? testId.slice(TAB_PREFIX.length) : null;
}

export function useContentLocale(): string | null {
	const [locale, setLocale] = useState<string | null>(() =>
		typeof document === "undefined" ? null : readActive(),
	);

	useEffect(() => {
		const sync = () => {
			setLocale((prev) => {
				const next = readActive();
				return next === prev ? prev : next;
			});
		};

		sync();

		// Radix flips aria-selected on the tab elements in place rather than
		// remounting the strip, so attribute changes are what has to be watched;
		// childList covers the strip mounting after this block does.
		const observer = new MutationObserver(sync);
		observer.observe(document.body, {
			subtree: true,
			childList: true,
			attributes: true,
			attributeFilter: ["aria-selected"],
		});

		return () => observer.disconnect();
	}, []);

	return locale;
}
