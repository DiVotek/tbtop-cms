<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Marks cms frontend requests for the boot-configured Inertia SSR gateway. */
class EnableSsr
{
    public const string REQUEST_ATTRIBUTE = 'tbtop_cms_ssr_enabled';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, (bool) config('tbtop-cms.ssr.enabled', false));

        return $next($request);
    }
}
