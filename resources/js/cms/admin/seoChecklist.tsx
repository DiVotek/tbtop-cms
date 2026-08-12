import { defineBlock, type RenderProps } from "@tbtop/inertia-admin";

type Status = "pass" | "warn" | "fail";

interface Check {
	key: string;
	status: Status;
	label: string;
	detail: string;
	required: boolean;
	/** The translated status word — the server owns every string shown here. */
	statusWord: string;
}

interface SeoChecklistOptions {
	checks: Check[];
	labels: {
		required: string;
		optional: string;
	};
}

/**
 * A dot rather than a worded badge. The badge carried the same three words down
 * the whole list — "OK, OK, OK, Check" — which is a lot of reading for a column
 * whose only job is to say where to look; colour does that in one glance, and
 * the words move into the title attribute for anyone who needs them spelled out.
 */
const DOT: Record<Status, string> = {
	pass: "bg-success",
	warn: "bg-warning",
	fail: "bg-destructive",
};

function ChecklistRow({ check, statusWord }: { check: Check; statusWord: string }) {
	return (
		<li className="flex items-start gap-2 border-b border-border/50 py-1.5 last:border-b-0">
			<span
				aria-hidden
				title={statusWord}
				className={`mt-1.5 size-1.5 shrink-0 rounded-full ${DOT[check.status]}`}
			/>
			<span className="text-xs leading-snug">
				<span className="font-medium text-foreground">{check.label}</span>
				{/* Passing checks need no explanation: "Set." under "Meta title"
				    says nothing the dot has not, so they read as a bare label and
				    the status word carries their whole meaning for a screen
				    reader. A warn or fail already explains itself in words, and
				    repeating the status there made the row say the same verdict
				    twice. */}
				{check.status === "pass" ? (
					<span className="sr-only"> — {statusWord}</span>
				) : (
					<span className="text-muted-foreground"> — {check.detail}</span>
				)}
			</span>
		</li>
	);
}

export function SeoChecklistBlock({ options }: RenderProps<SeoChecklistOptions>) {
	const required = options.checks.filter((c) => c.required);
	const optional = options.checks.filter((c) => !c.required);

	return (
		<div className="flex flex-col gap-3 rounded-lg border bg-card px-4 py-3">
			<Group title={options.labels.required} checks={required} />
			<Group title={options.labels.optional} checks={optional} />
		</div>
	);
}

function Group({ title, checks }: { title: string; checks: Check[] }) {
	if (checks.length === 0) {
		return null;
	}

	return (
		<div className="flex flex-col gap-1">
			<h4 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
				{title}
			</h4>
			<ul className="flex flex-col">
				{checks.map((check) => (
					<ChecklistRow key={check.key} check={check} statusWord={check.statusWord} />
				))}
			</ul>
		</div>
	);
}

export const seoChecklistBlock = defineBlock<"cms:seoChecklist", SeoChecklistOptions>(
	"cms:seoChecklist",
	{ behavior: "leaf", render: SeoChecklistBlock },
);
