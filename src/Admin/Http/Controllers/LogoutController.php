<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Counterpart to LoginPage::authenticate(): the admin package's
 * ProfileDropdown posts to '/logout' (root, unprefixed — it has no
 * awareness of the panel prefix), so this route is registered at root
 * from CmsServiceProvider rather than as a Page under {prefix}.
 */
class LogoutController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $guard = (string) config('tbtop-cms.admin.guard', 'web');

        auth()->guard($guard)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        return redirect('/' . trim($prefix, '/') . '/login');
    }
}
