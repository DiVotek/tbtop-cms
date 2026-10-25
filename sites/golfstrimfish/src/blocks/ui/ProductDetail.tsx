import type { BlockProps } from "../registry";

export function ProductDetail({ props }: BlockProps<"ProductDetail">) {
	if (props.price === null && props.unit === null) {
		return null;
	}
	return (
		<div className="mx-auto max-w-6xl px-4 md:px-6" data-testid="product-detail">
			<div className="inline-flex items-baseline gap-2 rounded-2xl bg-ocean-100 px-5 py-4">
				{props.price !== null ? (
					<>
						<span className="text-3xl font-bold tabular-nums text-ocean-900">{props.price}</span>
						<span className="text-lg text-ocean-800">₴</span>
					</>
				) : null}
				{props.unit ? <span className="text-sm text-ocean-800/80">за {props.unit}</span> : null}
			</div>
			{props.note ? <p className="mt-3 text-sm text-ocean-800/70">{props.note}</p> : null}
		</div>
	);
}
