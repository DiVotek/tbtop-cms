<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Admin\Support\CurrentAdmin;

class RedirectIfCmsAuthenticated
{
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $user = Auth::guard($guard)->user();

        // Only a CMS admin is sent on: a host-site user without CMS access
        // would bounce off EnsureCmsAdmin's 403 and never reach the form.
        if ($user !== null && CurrentAdmin::allows($user)) {
            // Hardcoded path, not a named route: the host app has no
            // `dashboard`/`home` route for Laravel's stock guest middleware to fall back to.
            return redirect(url(config('tbtop-cms.admin.prefix', 'admin') . '/dashboard'));
        }

        return $next($request);
    }
}
