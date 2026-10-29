import { usePage } from "@inertiajs/react";
import { JSONUIProvider } from "@json-render/react";
import type { CSSProperties } from "react";
import type { RenderSpec } from "./blockErrors";
import { catalog } from "./catalog";
import { CmsHead, type Seo } from "./CmsHead";
import { CmsRenderer } from "./CmsRenderer";
import { registry } from "./registry";

interface CmsPageProps {
    page?: { id: number | null; heading?: string | null };
    render: RenderSpec;
    seo: Seo;
    isPreview?: boolean;
    cms?: { debug?: boolean; strings?: { previewBanner?: string } };
    [key: string]: unknown;
}

const bannerStyle: CSSProperties = {
    position: "sticky",
    top: 0,
    zIndex: 9999,
    padding: "6px 12px",
    background: "#1f2937",
    color: "#ffffff",
    fontSize: "12px",
    textAlign: "center",
};

export default function CmsPage() {
    const { page, render, seo, isPreview, cms } = usePage<CmsPageProps>().props;
    // A 404/500 with no error page in the database comes with no blocks, only a heading (FallbackProps).
    const fallbackHeading = page?.id === null ? page.heading : null;

    return (
        <>
            <CmsHead seo={seo} />
            {isPreview ? <div style={bannerStyle}>{cms?.strings?.previewBanner ?? "Draft preview — this page is not published."}</div> : null}
            {fallbackHeading ? <h1>{fallbackHeading}</h1> : null}
            <JSONUIProvider registry={registry}>
                <CmsRenderer spec={render} catalog={catalog} registry={registry} showErrors={isPreview === true || cms?.debug === true} />
            </JSONUIProvider>
        </>
    );
}
