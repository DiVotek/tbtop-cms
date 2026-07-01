import { Head, usePage } from "@inertiajs/react";
import { JSONUIProvider, Renderer, type Spec } from "@json-render/react";
import { registry } from "./registry";

interface Seo {
    title: string;
    description: string;
    canonical: string;
    ogImage: string | null;
    noindex: boolean;
}

interface CmsPageProps {
    render: Spec;
    seo: Seo;
    [key: string]: unknown;
}

export default function CmsPage() {
    const { render, seo } = usePage<CmsPageProps>().props;

    return (
        <>
            <Head>
                <title>{seo.title}</title>
                {seo.description ? <meta name="description" content={seo.description} /> : null}
                {seo.canonical ? <link rel="canonical" href={seo.canonical} /> : null}
                {seo.noindex ? <meta name="robots" content="noindex" /> : null}
                {seo.ogImage ? <meta property="og:image" content={seo.ogImage} /> : null}
            </Head>
            <JSONUIProvider registry={registry}>
                <Renderer spec={render} registry={registry} />
            </JSONUIProvider>
        </>
    );
}
