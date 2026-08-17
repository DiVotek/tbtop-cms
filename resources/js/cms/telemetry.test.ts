import { expect, it } from "bun:test";
import {
    CMS_TELEMETRY_EVENT,
    emitCmsTelemetry,
} from "./telemetry";
import type { CmsTelemetryConfig } from "./telemetry.types";

const enabledConfig: CmsTelemetryConfig = {
    enabled: true,
    events: ["page_view"],
};

it("emits an enabled event through the browser boundary", () => {
    let received: unknown;
    const listener = (event: Event): void => {
        received = (event as CustomEvent).detail;
    };

    window.addEventListener(CMS_TELEMETRY_EVENT, listener);
    emitCmsTelemetry(enabledConfig, "page_view", { page_id: 42 });
    window.removeEventListener(CMS_TELEMETRY_EVENT, listener);

    expect(received).toMatchObject({
        name: "page_view",
        properties: { page_id: 42 },
    });
});

it("does not emit events disabled by configuration", () => {
    let received = false;
    const listener = (): void => {
        received = true;
    };

    window.addEventListener(CMS_TELEMETRY_EVENT, listener);
    emitCmsTelemetry({ enabled: false, events: ["page_view"] }, "page_view");
    window.removeEventListener(CMS_TELEMETRY_EVENT, listener);

    expect(received).toBe(false);
});
