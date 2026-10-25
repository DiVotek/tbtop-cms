import { definePage } from "@tbtop/admin";
import type { Cms } from "../cms";
import { type HealthStatus, runHealthChecks } from "../health";
import { listHeader, NAV } from "./support";

const STATUS: Record<HealthStatus, { label: string; color: string }> = {
	ok: { label: "OK", color: "success" },
	warning: { label: "Warning", color: "warning" },
	error: { label: "Error", color: "danger" },
};

/**
 * SiteHealthPage: the `cms doctor` checks, one row each with its status.
 * The checks run per view; PHP's table is a list here, since the admin
 * tables read the database.
 */
export function siteHealth(cms: Cms) {
	return definePage({
		path: "site-health",
		...listHeader("settings", "Site health", "Diagnostics for common install and configuration mistakes."),
		nav: { group: NAV.settings, label: "Site health", icon: "heart-pulse", order: 50 },
		view: async (s, { request }) => {
			const results = await runHealthChecks(cms.site(request));
			const badges = Object.fromEntries(Object.values(STATUS).map((status) => [status.label, { color: status.color }]));
			return s.stack([
				s.section({ variant: "card" }, [
					s.stack(
						results.map((result) =>
							s.grid({ cols: { sm: 1, md: 4 }, gap: 3, class: "border-t pt-3 first:border-t-0 first:pt-0" }, [
								s.displayValue(STATUS[result.status].label).badge(badges),
								s.displayText(result.label).variant("subheading"),
								s.stack([s.displayText(result.message)], { colSpan: { sm: 1, md: 2 } }),
							]),
						),
						{ gap: 3 },
					),
				]),
			]);
		},
	});
}
