import { describe, expect, it } from "bun:test";
import fixture from "./testing/pageProps.fixture.json";
import { cmsPage, pageProps } from "./testing/pages.generated";

// Written by tests/Frontend/PagePropsParityTest.php from the `page` prop of a real page of every fixture type and
// subtype; the schemas come from the same types (`composer types:blocks`).
const resolved: Record<string, Record<string, unknown>> = fixture;
const schemas: Record<string, (typeof pageProps)[keyof typeof pageProps]> = pageProps;

describe("fixture page types", () => {
    it("has a rendered page for every type and subtype in the generated schema", () => {
        expect(Object.keys(resolved).sort()).toEqual(Object.keys(schemas).sort());
    });

    for (const [key, cases] of Object.entries(resolved)) {
        for (const [name, page] of Object.entries(cases)) {
            it(`${key} (${name}): the page prop passes its own variant and the cmsPage union`, () => {
                expect(schemas[key].safeParse(page).error?.issues ?? []).toEqual([]);
                expect(cmsPage.safeParse(page).error?.issues ?? []).toEqual([]);
            });
        }
    }

    it("tells a subtype's page from its base's by the subtype literal", () => {
        expect(pageProps.event.safeParse(resolved.concert.full).success).toBe(false);
        expect(pageProps.concert.safeParse(resolved.event.full).success).toBe(false);
    });
});
