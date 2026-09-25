import { usePage } from "@inertiajs/react";
import { JSONUIProvider, Renderer, type Spec } from "@json-render/react";
import type { CSSProperties } from "react";
import { CmsHead, type Seo } from "./CmsHead";
import { registry } from "./registry";

interface CmsPageProps {
    render: Spec;
    seo: Seo;
    isPreview?: boolean;
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
    const { render, seo, isPreview } = usePage<CmsPageProps>().props;

    return (
        <>
            <CmsHead seo={seo} />
            {isPreview ? <div style={bannerStyle}>Draft preview — this page is not published.</div> : null}
            <JSONUIProvider registry={registry}>
                <Renderer spec={render} registry={registry} />
            </JSONUIProvider>
        </>
    );
}
