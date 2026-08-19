<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Counterpart to LoginPage::authenticate(): the admin client's
 * ProfileDropdown posts to `{prefix}/logout`, registered from
 * CmsServiceProvider (not a Page — it has no view).
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
