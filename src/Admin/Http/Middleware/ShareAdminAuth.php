<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the signed-in admin as Inertia's `auth.user` prop, which the
 * shell's profile menu reads. Only name and email cross the wire — never
 * the full user model.
 */
class ShareAdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $user = $request->user($guard);

        Inertia::share('auth', [
            'user' => $user !== null ? $this->present($user) : null,
        ]);

        return $next($request);
    }

    /** @return array{name: string|null, email: string|null} */
    private function present(Authenticatable $user): array
    {
        return [
            'name' => $this->attr($user, 'name'),
            'email' => $this->attr($user, 'email'),
        ];
    }

    private function attr(Authenticatable $user, string $key): ?string
    {
        $value = data_get($user, $key);

        return is_string($value) ? $value : null;
    }
}
