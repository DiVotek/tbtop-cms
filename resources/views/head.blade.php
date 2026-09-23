{{--
    Server-rendered <head> tags for crawlers/social bots that never run JS.
    Reads $page['props']['seo'] straight off the Inertia root view's own
    $page array (see app.blade.php) — defensively, since the root view is
    also reused by non-cms routes that pass no such prop (see
    CmsServiceProvider's `render.root_view` config). The `breadcrumbs` prop
    itself isn't read here: SeoResolver already folds it into seo.jsonLd's
    BreadcrumbList object, so this view and CmsPage.tsx's client <Head> both
    render that one pre-built payload rather than re-deriving it.

    Every tag below carries a `data-inertia="<key>"` attribute matching what
    CmsPage.tsx's client <Head> renders for the same tag (explicit `head-key`
    prop). That is how Inertia's head manager recognizes these DOM nodes as
    already-managed on hydration and updates them in place instead of
    duplicating them — see Renderer.isInertiaManagedElement/update in
    @inertiajs/core (node_modules/@inertiajs/core/dist/index.js, the
    `// src/head.ts` section). <title> is the one exception: Inertia matches
    it by tag name regardless of key, same as its own auto-generated
    `<title data-inertia="">`.

    When SSR is enabled (see EnableSsr) and the SSR gateway actually
    rendered this request, its head — produced by the same client <Head>
    code CmsPage.tsx uses — already covers everything below, so this view
    renders nothing to avoid duplicating it. Inertia\Ssr\SsrState is bound
    `scoped()` and memoizes the gateway dispatch per request (one call
    covers this include and the @inertiaHead directive that follows it in
    the host's root view), so asking it here is free of extra SSR round
    trips. If SSR is down or returns null, or the flag is off, this view
    renders the fallback tags below exactly as PR #101 did — SSR failure
    must never mean "no head". The request marker is checked first so the
    non-SSR path never touches SsrState at all.
--}}
@php
    /** @var array<string, mixed>|null $seo */
    $seo = $page['props']['seo'] ?? null;
    $ssrOwnsHead = (bool) request()->attributes->get(\Tbtop\Cms\Frontend\Http\Middleware\EnableSsr::REQUEST_ATTRIBUTE, false)
        && app(\Inertia\Ssr\SsrState::class)->dispatch() !== null;
@endphp
@if (is_array($seo) && ! $ssrOwnsHead)
    <title data-inertia="">{{ $seo['title'] ?? '' }}</title>
    @if (! empty($seo['description']))
        <meta data-inertia="meta-description" name="description" content="{{ $seo['description'] }}">
    @endif
    @if (! empty($seo['canonical']))
        <link data-inertia="link-canonical" rel="canonical" href="{{ $seo['canonical'] }}">
    @endif
    @if (! empty($seo['noindex']))
        <meta data-inertia="meta-robots" name="robots" content="noindex">
    @endif
    @if (! empty($seo['favicon']))
        <link data-inertia="link-icon" rel="icon" href="{{ $seo['favicon'] }}">
    @endif
    @if (! empty($seo['verificationGoogle']))
        <meta data-inertia="verification-google" name="google-site-verification" content="{{ $seo['verificationGoogle'] }}">
    @endif
    @if (! empty($seo['verificationBing']))
        <meta data-inertia="verification-bing" name="msvalidate.01" content="{{ $seo['verificationBing'] }}">
    @endif
    @php
        /** @var list<array{locale: string, url: string}> $alternates */
        $alternates = $seo['alternates'] ?? [];
    @endphp
    @if (count($alternates) > 1)
        @foreach ($alternates as $alternate)
            <link data-inertia="alternate-{{ $alternate['locale'] }}" rel="alternate" hreflang="{{ $alternate['locale'] }}" href="{{ $alternate['url'] }}">
        @endforeach
        @if (! empty($seo['xDefault']))
            <link data-inertia="alternate-x-default" rel="alternate" hreflang="x-default" href="{{ $seo['xDefault'] }}">
        @endif
    @endif
    <meta data-inertia="og-type" property="og:type" content="{{ $seo['ogType'] ?? 'website' }}">
    @if (! empty($seo['ogUrl']))
        <meta data-inertia="og-url" property="og:url" content="{{ $seo['ogUrl'] }}">
    @endif
    @if (! empty($seo['siteName']))
        <meta data-inertia="og-site-name" property="og:site_name" content="{{ $seo['siteName'] }}">
    @endif
    @if (! empty($seo['ogLocale']))
        <meta data-inertia="og-locale" property="og:locale" content="{{ $seo['ogLocale'] }}">
    @endif
    @foreach (($seo['ogLocaleAlternates'] ?? []) as $alternateLocale)
        <meta data-inertia="og-locale-alternate-{{ $alternateLocale }}" property="og:locale:alternate" content="{{ $alternateLocale }}">
    @endforeach
    @if (! empty($seo['ogTitle']))
        <meta data-inertia="og-title" property="og:title" content="{{ $seo['ogTitle'] }}">
    @endif
    @if (! empty($seo['ogDescription']))
        <meta data-inertia="og-description" property="og:description" content="{{ $seo['ogDescription'] }}">
    @endif
    @if (! empty($seo['ogImage']))
        <meta data-inertia="og-image" property="og:image" content="{{ $seo['ogImage'] }}">
        @if (! empty($seo['ogImageAlt']))
            <meta data-inertia="og-image-alt" property="og:image:alt" content="{{ $seo['ogImageAlt'] }}">
        @endif
        @if (! empty($seo['ogImageType']))
            <meta data-inertia="og-image-type" property="og:image:type" content="{{ $seo['ogImageType'] }}">
        @endif
        @if (! empty($seo['ogImageWidth']) && ! empty($seo['ogImageHeight']))
            <meta data-inertia="og-image-width" property="og:image:width" content="{{ $seo['ogImageWidth'] }}">
            <meta data-inertia="og-image-height" property="og:image:height" content="{{ $seo['ogImageHeight'] }}">
        @endif
    @endif
    @if (($seo['ogType'] ?? 'website') === 'article')
        @if (! empty($seo['articlePublishedTime']))
            <meta data-inertia="article-published-time" property="article:published_time" content="{{ $seo['articlePublishedTime'] }}">
        @endif
        @if (! empty($seo['articleModifiedTime']))
            <meta data-inertia="article-modified-time" property="article:modified_time" content="{{ $seo['articleModifiedTime'] }}">
        @endif
        @if (! empty($seo['articleAuthor']))
            <meta data-inertia="article-author" property="article:author" content="{{ $seo['articleAuthor'] }}">
        @endif
    @endif
    <meta data-inertia="twitter-card" name="twitter:card" content="{{ $seo['twitterCard'] ?? 'summary' }}">
    @if (! empty($seo['ogTitle']))
        <meta data-inertia="twitter-title" name="twitter:title" content="{{ $seo['ogTitle'] }}">
    @endif
    @if (! empty($seo['ogDescription']))
        <meta data-inertia="twitter-description" name="twitter:description" content="{{ $seo['ogDescription'] }}">
    @endif
    @if (! empty($seo['ogImage']))
        <meta data-inertia="twitter-image" name="twitter:image" content="{{ $seo['ogImage'] }}">
        @if (! empty($seo['ogImageAlt']))
            <meta data-inertia="twitter-image-alt" name="twitter:image:alt" content="{{ $seo['ogImageAlt'] }}">
        @endif
    @endif
    @php
        /** @var list<array<string, mixed>> $jsonLdObjects */
        $jsonLdObjects = $seo['jsonLd'] ?? [];
    @endphp
    @foreach ($jsonLdObjects as $object)
        {{-- Keyed by position, not @type: SeoResolver::jsonLd() always emits WebSite (if any) before
             BreadcrumbList (if any), and CmsPage.tsx's client-side jsonLd.map() renders in that same
             order, so index is a stable, order-matching key without either side parsing @type. --}}
        <script data-inertia="jsonld-{{ $loop->index }}" type="application/ld+json">{!! json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endforeach
@endif
