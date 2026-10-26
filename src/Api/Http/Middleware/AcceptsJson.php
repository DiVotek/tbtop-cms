<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every API answer is JSON, errors included, whatever the client's Accept header says. */
final class AcceptsJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
