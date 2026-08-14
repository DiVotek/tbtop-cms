import { Head, usePage } from "@inertiajs/react";
import { JSONUIProvider, Renderer, type Spec } from "@json-render/react";
import type { CSSProperties, DetailedHTMLProps, LinkHTMLAttributes } from "react";
import { registry } from "./registry";

// Inertia's <Head> renders children through its own string builder (Head.ts,
// renderTagStart), keyed off the literal JSX prop names — not through React's
// DOM renderer, which is the only reason this repo can otherwise rely on
// React's stricter `hrefLang` JSX typing. The output HTML attribute must be
// the literal lowercase `hreflang` to byte-match head.blade.php (both feed
// the same Inertia head-manager dedup/DOM-diff), so this alias widens just
// that one attribute to accept it.
type AlternateLinkProps = Omit<DetailedHTMLProps<LinkHTMLAttributes<HTMLLinkElement>, HTMLLinkElement>, "hrefLang"> & {
    hreflang: string;
    "head-key": string;
};
const AlternateLink = "link" as unknown as (props: AlternateLinkProps) => null;

interface SeoAlternate {
    locale: string;
    url: string;
}

interface Seo {
    title: string;
    description: string;
    canonical: string;
    ogImage: string | null;
    ogTitle: string;
    ogDescription: string;
    noindex: boolean;
    siteName: string;
    ogType: string;
    ogUrl: string;
    ogLocale: string;
    ogLocaleAlternates: string[];
    twitterCard: string;
    alternates: SeoAlternate[];
    xDefault: string | null;
    favicon: string | null;
    verificationGoogle: string | null;
    verificationBing: string | null;
    jsonLd: Record<string, unknown>[];
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
    const showAlternates = seo.alternates.length > 1;

    return (
        <>
            <Head title={seo.title}>
                {seo.description ? <meta head-key="meta-description" name="description" content={seo.description} /> : null}
                {seo.canonical ? <link head-key="link-canonical" rel="canonical" href={seo.canonical} /> : null}
                {seo.noindex ? <meta head-key="meta-robots" name="robots" content="noindex" /> : null}
                {seo.favicon ? <link head-key="link-icon" rel="icon" href={seo.favicon} /> : null}
                {seo.verificationGoogle ? (
                    <meta head-key="verification-google" name="google-site-verification" content={seo.verificationGoogle} />
                ) : null}
                {seo.verificationBing ? <meta head-key="verification-bing" name="msvalidate.01" content={seo.verificationBing} /> : null}
                {/* GTM is not rendered here: the InjectGtm middleware inserts Google's
                    snippet into the full-page HTML outside Inertia's head manager, so it
                    survives hydration and SPA navigations without being re-inserted. */}
                {showAlternates
                    ? seo.alternates.map((alternate) => (
                          <AlternateLink
                              key={alternate.locale}
                              head-key={`alternate-${alternate.locale}`}
                              rel="alternate"
                              hreflang={alternate.locale}
                              href={alternate.url}
                          />
                      ))
                    : null}
                {showAlternates && seo.xDefault ? (
                    <AlternateLink head-key="alternate-x-default" rel="alternate" hreflang="x-default" href={seo.xDefault} />
                ) : null}
                <meta head-key="og-type" property="og:type" content={seo.ogType} />
                {seo.ogUrl ? <meta head-key="og-url" property="og:url" content={seo.ogUrl} /> : null}
                {seo.siteName ? <meta head-key="og-site-name" property="og:site_name" content={seo.siteName} /> : null}
                <meta head-key="og-locale" property="og:locale" content={seo.ogLocale} />
                {seo.ogLocaleAlternates.map((locale) => (
                    <meta key={locale} head-key={`og-locale-alternate-${locale}`} property="og:locale:alternate" content={locale} />
                ))}
                {seo.ogTitle ? <meta head-key="og-title" property="og:title" content={seo.ogTitle} /> : null}
                {seo.ogDescription ? <meta head-key="og-description" property="og:description" content={seo.ogDescription} /> : null}
                {seo.ogImage ? <meta head-key="og-image" property="og:image" content={seo.ogImage} /> : null}
                <meta head-key="twitter-card" name="twitter:card" content={seo.twitterCard} />
                {seo.ogTitle ? <meta head-key="twitter-title" name="twitter:title" content={seo.ogTitle} /> : null}
                {seo.ogDescription ? (
                    <meta head-key="twitter-description" name="twitter:description" content={seo.ogDescription} />
                ) : null}
                {seo.ogImage ? <meta head-key="twitter-image" name="twitter:image" content={seo.ogImage} /> : null}
                {seo.jsonLd.map((object, index) => (
                    // index is the stable identity here (see head.blade.php's matching comment), not a list-reorder key.
                    <script key={index} head-key={`jsonld-${index}`} type="application/ld+json">
                        {/* Must not let a page title/label containing "</script>" break out of the
                            script tag — json_encode(JSON_HEX_TAG) is the server-side equivalent of
                            this same defense, see head.blade.php. */}
                        {JSON.stringify(object).replace(/</g, "\\u003c")}
                    </script>
                ))}
            </Head>
            {isPreview ? <div style={bannerStyle}>Draft preview — this page is not published.</div> : null}
            <JSONUIProvider registry={registry}>
                <Renderer spec={render} registry={registry} />
            </JSONUIProvider>
        </>
    );
}
