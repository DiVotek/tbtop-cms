import { describe, expect, it } from "bun:test";
import type { z } from "zod";
import { blockProps } from "./blocks.generated";
import fixture from "./blockProps.fixture.json";
import { blockProps as formBlockProps } from "./testing/formBlocks.generated";
import formFixture from "./testing/formBlockProps.fixture.json";

// Written by tests/Frontend/BlockPropsParityTest.php from the props each block reaches the client with:
// the package's own blocks, and the form-driven fixture blocks (one field per FormResolver rule).
const suites: [string, Record<string, z.ZodType>, Record<string, Record<string, unknown>>][] = [
    ["package blocks", blockProps, fixture],
    ["form-driven fixture blocks", formBlockProps, formFixture],
];

for (const [suite, schemas, resolved] of suites) {
    describe(suite, () => {
        it("has resolved props for every block in the generated catalog", () => {
            expect(Object.keys(resolved).sort()).toEqual(Object.keys(schemas).sort());
        });

        for (const [kind, cases] of Object.entries(resolved)) {
            for (const [name, props] of Object.entries(cases)) {
                it(`${kind} (${name}): resolve() output passes the generated schema`, () => {
                    const result = schemas[kind].safeParse(props);

                    expect(result.error?.issues ?? []).toEqual([]);
                });
            }
        }
    });
}
