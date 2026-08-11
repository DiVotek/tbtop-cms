{{--
    Body-start markup for cms frontend pages — currently the noscript half of
    Google's GTM install snippet, whose rules require it immediately after the
    opening <body>. Host apps include this right after <body> in their root
    view (see the README's "Server-rendered <head>" section):

        <body>
            @include('tbtop-cms::body')
            @inertia

    Reads the same Inertia root-view $page array as head.blade.php, and the
    same guard applies: non-cms routes that reuse the root view pass no seo
    prop and render nothing here. The container id is validated to
    ^GTM-[A-Z0-9]+$ in SeoResolver::gtmId() before it can reach this URL.
--}}
@php
    /** @var array<string, mixed>|null $seo */
    $seo = $page['props']['seo'] ?? null;
@endphp
@if (is_array($seo) && ! empty($seo['gtmId']))
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $seo['gtmId'] }}"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
@endif
