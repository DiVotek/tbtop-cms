<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Api\ApiAccess;
use Tbtop\Cms\Api\ApiLocales;

/**
 * Puts the token's user on the panel guard for this request, so created_by,
 * revisions and the audit causer name it as they name an editor. The user
 * must still be a CMS admin (and hold the pages ability when roles are on):
 * revoking admin access silences the token too. setUser() writes no session.
 */
final class ActAsPanelUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user(ApiAccess::GUARD);
        abort_unless($user instanceof Authenticatable && CurrentAdmin::allows($user), 403, __('tbtop-cms::api.errors.not_admin'));
        abort_if(CmsAbilities::enabled() && Gate::forUser($user)->denies('tbtop-cms.pages'), 403, __('tbtop-cms::api.errors.not_admin'));

        Auth::guard(CurrentAdmin::guard())->setUser($user);
        app(ApiLocales::class)->syncContentLocales();

        return $next($request);
    }
}
