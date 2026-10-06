import { expect, it } from "bun:test";
import { blockProps } from "./blocks.generated";
import fixture from "./blockProps.fixture.json";

// Written by tests/Frontend/BlockPropsParityTest.php from the props each package block reaches the client with.
const resolved: Record<string, Record<string, unknown>> = fixture;

it("has resolved props for every block in the generated catalog", () => {
    expect(Object.keys(resolved).sort()).toEqual(Object.keys(blockProps).sort());
});

for (const [kind, cases] of Object.entries(resolved)) {
    for (const [name, props] of Object.entries(cases)) {
        it(`${kind} (${name}): resolve() output passes the generated schema`, () => {
            const schema = blockProps[kind as keyof typeof blockProps];
            const result = schema.safeParse(props);

            expect(result.error?.issues ?? []).toEqual([]);
        });
    }
}
