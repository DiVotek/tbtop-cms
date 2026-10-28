<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support\Wiring;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tbtop\Cms\Admin\CmsPanel;
use Tbtop\Cms\Admin\Http\Controllers\LogoutController;
use Tbtop\Cms\Admin\Http\Middleware\EnsureCmsAdmin;
use Tbtop\Cms\Admin\Listeners\SeedsLocaleFromProfile;
use Tbtop\Cms\Collection\Http\EntrySearchController;
use Tbtop\Cms\Collection\Http\EntrySearchRoute;
use Tbtop\Cms\Link\Http\LinkPageSearchController;
use Tbtop\Cms\Link\LinkPageSearchRoute;

/**
 * Admin entry points cms owns on the host: the panel registration, the `login`
 * redirect, logout, the link page search and entry search routes, and the login listener that
 * seeds the admin locale.
 *
 * Called from CmsServiceProvider::packageBooted().
 */
final class AdminWiring
{
    public static function wire(Application $app): void
    {
        self::wireAdminLoginRedirect();
        self::wireAdminLogout();
        self::wireLinkPageSearch();
        self::wireEntrySearch();
        self::wireLocaleSeeding();
    }

    /**
     * Adds CmsPanel to `tbtop-admin.panels`. Runs from packageRegistered(),
     * before the admin package reads the list.
     */
    public static function registerPanel(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        /** @var list<class-string> $panels */
        $panels = (array) config('tbtop-admin.panels', []);

        if (! in_array(CmsPanel::class, $panels, true)) {
            $panels[] = CmsPanel::class;
            config(['tbtop-admin.panels' => $panels]);
        }
    }

    /**
     * Guarded admin routes rely on a named `login` route: `auth`'s
     * unauthenticated handler redirects there. A bare host has none, so cms
     * provides one pointing at the admin login page.
     *
     * A host that ships its own auth (a Laravel starter kit, Fortify,
     * Breeze) already defines `login`, and registering a second route under
     * that name is actively harmful: Laravel keeps the first binding for
     * URL generation but the last matching route wins dispatch, so
     * `route('login')` would still print the host's URL while GET /login
     * bounced to the admin panel — the host's own login page unreachable.
     * It also emits a duplicate export in Wayfinder's generated routes,
     * breaking the host's frontend build. So it is only registered when
     * nothing else claims the name.
     *
     * The collection is scanned directly rather than asked via Route::has():
     * the name lookup it consults is a cache rebuilt by refreshNameLookups(),
     * and during boot that cache still predates routes the host registered,
     * so has() reports false for a name that is demonstrably present.
     */
    private static function wireAdminLoginRedirect(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        if (config('tbtop-cms.admin.pages.login') === null) {
            return;
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === 'login') {
                return;
            }
        }

        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        Route::redirect('login', $prefix . '/login')->name('login');
    }

    /**
     * Server half of the admin client's logout contract: ProfileDropdown
     * (admin >=0.2.24) posts to `{prefix}/logout`. Registered by cms because
     * auth is cms-owned — the admin package ships no auth controllers.
     * Prefixed on purpose: a root `/logout` would occupy the host app's own
     * route namespace. Guarded by `auth:{guard}` only: logging out must not
     * require the CmsUserProfile row EnsureCmsAdmin checks for page access.
     */
    private static function wireAdminLogout(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        Route::middleware(['web', 'auth:' . $guard])
            ->post($prefix . '/logout', LogoutController::class)
            ->name('tbtop.cms.logout');
    }

    /** One fixed route, not a per-field query() closure: PrefixedField::rename() cannot carry a closure. */
    private static function wireLinkPageSearch(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        Route::middleware(['web', 'auth:' . $guard, EnsureCmsAdmin::class])
            ->post($prefix . '/' . LinkPageSearchRoute::RELATIVE_PATH, LinkPageSearchController::class)
            ->name(LinkPageSearchRoute::NAME);
    }

    /** The collection field's picker; same guard as the link page search. */
    private static function wireEntrySearch(): void
    {
        if (! (bool) config('tbtop-cms.admin.autoregister', true)) {
            return;
        }

        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        Route::middleware(['web', 'auth:' . $guard, EnsureCmsAdmin::class])
            ->post($prefix . '/' . EntrySearchRoute::RELATIVE_PATH, EntrySearchController::class)
            ->name(EntrySearchRoute::NAME);
    }

    /**
     * Seeds session('tbtop.locale') from the profile right after login —
     * see SeedsLocaleFromProfile's docblock for why this is needed.
     */
    private static function wireLocaleSeeding(): void
    {
        Event::listen(Login::class, SeedsLocaleFromProfile::class);
    }
}
