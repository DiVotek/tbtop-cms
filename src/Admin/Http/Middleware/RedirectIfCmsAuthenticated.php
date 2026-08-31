<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfCmsAuthenticated
{
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        if (Auth::guard($guard)->check()) {
            // Hardcoded path, not a named route: the host app has no
            // `dashboard`/`home` route for Laravel's stock guest middleware to fall back to.
            return redirect(url(config('tbtop-cms.admin.prefix', 'admin') . '/dashboard'));
        }

        return $next($request);
    }
}
