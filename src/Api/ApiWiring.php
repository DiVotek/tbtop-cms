<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Sanctum;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Api\Http\Controllers\LocalesController;
use Tbtop\Cms\Api\Http\Controllers\MediaController;
use Tbtop\Cms\Api\Http\Controllers\PagesController;
use Tbtop\Cms\Api\Http\Controllers\TypeFieldsController;
use Tbtop\Cms\Api\Http\Middleware\AcceptsJson;
use Tbtop\Cms\Api\Http\Middleware\ActAsPanelUser;

/**
 * The pages API under /api/cms/v1, registered only when `tbtop-cms.api.enabled`
 * is on and the host has installed laravel/sanctum (PagesApiCheck says which
 * one is missing). Routes land before the host's web.php, so the frontend
 * catch-all does not shadow them.
 */
final class ApiWiring
{
    public const string PREFIX = 'api/cms/v1';

    public static function isEnabled(): bool
    {
        return (bool) config('tbtop-cms.api.enabled', false);
    }

    public static function isSanctumInstalled(): bool
    {
        return class_exists(Sanctum::class);
    }

    public static function wire(): void
    {
        if (! self::isEnabled() || ! self::isSanctumInstalled()) {
            return;
        }

        self::registerGuard();
        self::registerRoutes();
    }

    /** A host may define `cms-api` itself; otherwise it reads tokens of the panel guard's users. */
    private static function registerGuard(): void
    {
        if (config('auth.guards.' . ApiAccess::GUARD) !== null) {
            return;
        }

        config(['auth.guards.' . ApiAccess::GUARD => [
            'driver' => 'sanctum',
            'provider' => config('auth.guards.' . CurrentAdmin::guard() . '.provider'),
        ]]);
    }

    private static function registerRoutes(): void
    {
        $read = CheckAbilities::class . ':' . TokenScope::READ;
        $write = CheckAbilities::class . ':' . TokenScope::WRITE;
        $throttle = (string) config('tbtop-cms.api.throttle', '60,1');

        Route::prefix(self::PREFIX)
            ->middleware([
                AcceptsJson::class,
                SubstituteBindings::class,
                'auth:' . ApiAccess::GUARD,
                ActAsPanelUser::class,
                // After auth, so the limit is counted per token user, not per IP.
                ...($throttle === '' ? [] : ['throttle:' . $throttle]),
            ])
            ->name('tbtop-cms.api.')
            ->group(static function () use ($read, $write): void {
                Route::get('pages', [PagesController::class, 'index'])->middleware($read)->name('pages.index');
                Route::get('pages/{page}', [PagesController::class, 'show'])->middleware($read)->whereNumber('page')->name('pages.show');
                Route::get('types/{type}/fields', TypeFieldsController::class)->middleware($read)->name('types.fields');
                Route::get('locales', LocalesController::class)->middleware($read)->name('locales');

                Route::post('pages', [PagesController::class, 'store'])->middleware($write)->name('pages.store');
                Route::patch('pages/{page}', [PagesController::class, 'update'])->middleware($write)->whereNumber('page')->name('pages.update');
                Route::delete('pages/{page}', [PagesController::class, 'destroy'])->middleware($write)->whereNumber('page')->name('pages.destroy');
                Route::post('pages/{page}/publish', [PagesController::class, 'publish'])->middleware($write)->whereNumber('page')->name('pages.publish');
                Route::post('media', MediaController::class)->middleware($write)->name('media.store');
            });
    }
}
