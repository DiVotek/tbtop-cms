<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\SiteAbility;

/**
 * Not Sanctum's CheckForAnyAbility: that lets a `*` token through every
 * route, and a site ability must be named in the token itself.
 */
final class RequiresSiteAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        abort_unless(ApiAccess::scope($request)->canSite(SiteAbility::from($ability)), 403, __('tbtop-cms::api.errors.no_ability'));

        return $next($request);
    }
}
