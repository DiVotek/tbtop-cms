import { createContext, useContext } from "react";
import type {
    CmsTelemetryConfig,
    CmsTelemetryEventName,
    CmsTelemetryPayload,
    CmsTelemetryProperties,
} from "./telemetry.types";

export const CMS_TELEMETRY_EVENT = "tbtop:cms:telemetry";
export const CmsTelemetryContext = createContext<CmsTelemetryConfig | undefined>(undefined);

export function useCmsTelemetry(): CmsTelemetryConfig | undefined {
    return useContext(CmsTelemetryContext);
}

export function emitCmsTelemetry(
    config: CmsTelemetryConfig | undefined,
    name: CmsTelemetryEventName,
    properties: CmsTelemetryProperties = {},
): void {
    if (!isEnabled(config, name) || typeof window === "undefined") {
        return;
    }

    const detail: CmsTelemetryPayload = {
        name,
        properties,
        timestamp: Date.now(),
    };

    window.dispatchEvent(new CustomEvent<CmsTelemetryPayload>(CMS_TELEMETRY_EVENT, { detail }));
}

function isEnabled(config: CmsTelemetryConfig | undefined, name: CmsTelemetryEventName): boolean {
    return config?.enabled === true && config.events.includes(name);
}
