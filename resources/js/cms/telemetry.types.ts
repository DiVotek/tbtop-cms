export type CmsTelemetryEventName =
    | "page_view"
    | "form_submit"
    | "form_success"
    | "form_error";

export type CmsTelemetryProperties = Record<string, boolean | number | string>;

export type CmsTelemetryConfig = {
    enabled: boolean;
    events: CmsTelemetryEventName[];
};

export type CmsTelemetryPayload = {
    name: CmsTelemetryEventName;
    properties: CmsTelemetryProperties;
    timestamp: number;
};

export type CmsSharedProps = {
    telemetry?: CmsTelemetryConfig;
};
