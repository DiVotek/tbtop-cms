<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Frontend\Seo\GtmContainerId;
use Tbtop\Cms\Settings\Settings;

/**
 * Injects both halves of Google's Tag Manager install snippet into full-page
 * frontend HTML responses when `seo.gtm_container_id` is set: the loader
 * `<script>` right after the opening `<head>` tag (Google asks for "as high
 * in the head as possible"), the `<noscript>` iframe right after the opening
 * `<body>` tag.
 *
 * Injection happens here — post-render, Debugbar-style — instead of in a
 * blade partial so a fresh install works with zero root-view wiring beyond
 * the existing `tbtop-cms::head` include, and only frontend routes ever get
 * the snippet (this middleware is registered in Cms::frontendRoutes(); the
 * admin panel never passes through it). Eligibility is gated on an HTML
 * content type and a non-Inertia/non-XHR request — see isInjectable() — so
 * the sitemap XML, robots.txt, and form-submit JSON sharing this route group
 * are never rewritten. That also means only full page loads carry the
 * snippet, which is where GTM belongs: the tags survive SPA navigation, and
 * GTM tracks route changes via its own history triggers.
 *
 * The container id is validated to ^GTM-[A-Z0-9]+$ (GtmContainerId::valid()
 * via fromSettings()) before it is interpolated into the markup.
 */
class InjectGtm
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isInjectable($request, $response)) {
            return $response;
        }

        $id = GtmContainerId::fromSettings($this->settings);

        if ($id === null) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || ! str_starts_with(ltrim($content), '<')) {
            return $response;
        }

        $injected = (string) preg_replace(
            '/<head[^>]*>/i',
            '$0' . "\n" . $this->headScript($id),
            $content,
            1,
            $headCount,
        );

        if ($headCount !== 1) {
            return $response;
        }

        $injected = (string) preg_replace(
            '/<body[^>]*>/i',
            '$0' . "\n" . $this->bodyNoscript($id),
            $injected,
            1,
        );

        $response->setContent($injected);

        return $response;
    }

    /**
     * Only full-page HTML responses to ordinary GET navigations are eligible.
     * The same route group also serves robots.txt, the sitemap XML, and the
     * form-submit JSON endpoint — none of which may be rewritten just because
     * user-authored content happens to contain a "<head…>"-looking string.
     * Streamed and binary responses are skipped too: their content is not a
     * string to rewrite.
     */
    private function isInjectable(Request $request, Response $response): bool
    {
        if ($request->inertia() || $request->ajax() || $request->wantsJson()) {
            return false;
        }

        if (! $response instanceof HttpResponse) {
            return false;
        }

        if ($response->isRedirection()) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        return str_contains(strtolower($contentType), 'text/html');
    }

    /** Google's stock loader snippet, verbatim except for the injected id. */
    private function headScript(string $id): string
    {
        return "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':"
            . "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],"
            . "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src="
            . "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);"
            . "})(window,document,'script','dataLayer','{$id}');</script>";
    }

    private function bodyNoscript(string $id): string
    {
        return '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $id . '"'
            . ' height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
    }
}
