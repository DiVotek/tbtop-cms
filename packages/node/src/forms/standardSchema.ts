/**
 * The Standard Schema v1 interface (https://standardschema.dev), copied as
 * the spec invites: zod, valibot and arktype schemas all satisfy it, so a
 * form block's `submit.schema` takes any of them without a dependency here.
 */
export interface StandardSchemaV1<Input = unknown, Output = Input> {
	readonly "~standard": StandardSchemaV1.Props<Input, Output>;
}

export declare namespace StandardSchemaV1 {
	interface Props<Input = unknown, Output = Input> {
		readonly version: 1;
		readonly vendor: string;
		readonly validate: (value: unknown) => Result<Output> | Promise<Result<Output>>;
		readonly types?: Types<Input, Output> | undefined;
	}
	type Result<Output> = SuccessResult<Output> | FailureResult;
	interface SuccessResult<Output> {
		readonly value: Output;
		readonly issues?: undefined;
	}
	interface FailureResult {
		readonly issues: ReadonlyArray<Issue>;
	}
	interface Issue {
		readonly message: string;
		readonly path?: ReadonlyArray<PropertyKey | PathSegment> | undefined;
	}
	interface PathSegment {
		readonly key: PropertyKey;
	}
	interface Types<Input = unknown, Output = Input> {
		readonly input: Input;
		readonly output: Output;
	}
	type InferOutput<Schema extends StandardSchemaV1> = NonNullable<Schema["~standard"]["types"]>["output"];
}

export type SchemaOutcome<T> = { ok: true; value: T } | { ok: false; errors: Record<string, string[]> };

/**
 * Validates `input` and maps the issues to the 422 shape Laravel answers
 * with: messages by top-level field, `form` for an issue with no path.
 */
export async function validateWith<T>(schema: StandardSchemaV1<unknown, T>, input: unknown): Promise<SchemaOutcome<T>> {
	const result = await schema["~standard"].validate(input);
	if (result.issues === undefined) {
		return { ok: true, value: result.value };
	}
	const errors: Record<string, string[]> = {};
	for (const issue of result.issues) {
		const first = issue.path?.[0];
		const key = first === undefined ? "form" : String(typeof first === "object" ? first.key : first);
		(errors[key] ??= []).push(issue.message);
	}
	return { ok: false, errors };
}
