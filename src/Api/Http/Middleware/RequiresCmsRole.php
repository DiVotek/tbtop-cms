<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Api\ApiAccess;

/**
 * With `tbtop-cms.roles` on, the token's user needs one of the section
 * abilities the route belongs to, as the admin page of that section does:
 * `tbtop-cms.pages` for pages, `tbtop-cms.redirects` for the 404 log and redirects.
 */
final class RequiresCmsRole
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        if (CmsAbilities::enabled()) {
            $user = $request->user(ApiAccess::GUARD);
            $isAllowed = $user !== null && array_filter($abilities, static fn (string $ability): bool => Gate::forUser($user)->allows($ability)) !== [];
            abort_unless($isAllowed, 403, __('tbtop-cms::api.errors.not_admin'));
        }

        return $next($request);
    }
}
