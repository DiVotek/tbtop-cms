<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Models\User;

/**
 * Dev convenience: signs the seeded admin in automatically so the workbench
 * admin panel is reachable without a login round-trip. Never runs under tests.
 */
class AutoLoginAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->runningUnitTests() && Auth::guard()->guest()) {
            $admin = User::query()->where('email', 'admin@admin.com')->first();

            if ($admin !== null) {
                Auth::guard()->setUser($admin);
            }
        }

        return $next($request);
    }
}
