<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Admin\Support\CurrentAdmin;

class EnsureCmsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user(CurrentAdmin::guard());

        if ($user !== null && ! CurrentAdmin::allows($user)) {
            abort(403);
        }

        return $next($request);
    }
}
