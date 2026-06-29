<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Admin\Contracts\CmsAdmin;
use Tbtop\Cms\Admin\Models\CmsUserProfile;

class EnsureCmsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $user = $request->user($guard);

        if ($user !== null && ! $this->canAccess($user)) {
            abort(403);
        }

        return $next($request);
    }

    private function canAccess(Authenticatable $user): bool
    {
        if ($user instanceof CmsAdmin) {
            return $user->canAccessCmsAdmin();
        }

        return CmsUserProfile::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->exists();
    }
}
