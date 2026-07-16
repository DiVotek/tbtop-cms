import { Head, usePage } from "@inertiajs/react";
import { JSONUIProvider, Renderer, type Spec } from "@json-render/react";
import type { CSSProperties } from "react";
import { registry } from "./registry";

interface Seo {
    title: string;
    description: string;
    canonical: string;
    ogImage: string | null;
    ogTitle: string;
    ogDescription: string;
    noindex: boolean;
}

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
            <Head>
                <title>{seo.title}</title>
                {seo.description ? <meta name="description" content={seo.description} /> : null}
                {seo.canonical ? <link rel="canonical" href={seo.canonical} /> : null}
                {seo.noindex ? <meta name="robots" content="noindex" /> : null}
                {seo.ogTitle ? <meta property="og:title" content={seo.ogTitle} /> : null}
                {seo.ogDescription ? <meta property="og:description" content={seo.ogDescription} /> : null}
                {seo.ogImage ? <meta property="og:image" content={seo.ogImage} /> : null}
            </Head>
            {isPreview ? <div style={bannerStyle}>Draft preview — this page is not published.</div> : null}
            <JSONUIProvider registry={registry}>
                <Renderer spec={render} registry={registry} />
            </JSONUIProvider>
        </>
    );
}
