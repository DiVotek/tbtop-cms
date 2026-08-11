<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flips `inertia.ssr.enabled` on for the current request only, when the cms
 * flag (`tbtop-cms.ssr.enabled`) is on. Scoped to the cms frontend route
 * group so the host's admin responses (which never run this middleware)
 * never go through SSR, without the host having to touch its global
 * `inertia.ssr.enabled` config. Safe per-request: Inertia's SsrState is
 * bound `scoped()` and reads this config lazily when its response view
 * renders, i.e. after middleware has run.
 *
 * The previous value is restored after $next() returns: config is
 * application-global, so on a long-lived worker (Octane, without relying on
 * its config sandbox) a raised flag would otherwise outlive this request and
 * SSR a later admin request in the same process. Restoring there is safe
 * ordering-wise — the Inertia response (and its SSR dispatch) is rendered by
 * Router::prepareResponse() inside the $next() chain, before control
 * returns here.
 */
class EnableSsr
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('tbtop-cms.ssr.enabled', false)) {
            return $next($request);
        }

        $previous = (bool) config('inertia.ssr.enabled');
        config(['inertia.ssr.enabled' => true]);

        try {
            return $next($request);
        } finally {
            config(['inertia.ssr.enabled' => $previous]);
        }
    }
}
