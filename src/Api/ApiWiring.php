<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Laravel\Sanctum\Sanctum;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Api\Http\Controllers\LocalesController;
use Tbtop\Cms\Api\Http\Controllers\MediaController;
use Tbtop\Cms\Api\Http\Controllers\NotFoundController;
use Tbtop\Cms\Api\Http\Controllers\PagesController;
use Tbtop\Cms\Api\Http\Controllers\RedirectsController;
use Tbtop\Cms\Api\Http\Controllers\TokenController;
use Tbtop\Cms\Api\Http\Controllers\TypeFieldsController;
use Tbtop\Cms\Api\Http\Middleware\AcceptsJson;
use Tbtop\Cms\Api\Http\Middleware\ActAsPanelUser;
use Tbtop\Cms\Api\Http\Middleware\RequiresCmsRole;
use Tbtop\Cms\Api\Http\Middleware\RequiresSiteAbility;

/**
 * The pages API, and the site's 404 log and redirects, under /api/cms/v1, registered only when `tbtop-cms.api.enabled`
 * is on and the host has installed laravel/sanctum (PagesApiCheck says which
 * one is missing). Routes land before the host's web.php, so the frontend
 * catch-all does not shadow them.
 */
final class ApiWiring
{
    public const string PREFIX = 'api/cms/v1';

    private const string PAGES_ROLE = 'tbtop-cms.pages';

    private const string REDIRECTS_ROLE = 'tbtop-cms.redirects';

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
        $can = static fn (TokenAction $action): string => CheckForAnyAbility::class . ':' . implode(',', $action->accepted());
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
            ->group(static function () use ($can): void {
                Route::get('token', TokenController::class)
                    ->middleware(RequiresCmsRole::class . ':' . self::PAGES_ROLE . ',' . self::REDIRECTS_ROLE)->name('token');

                Route::middleware(RequiresCmsRole::class . ':' . self::PAGES_ROLE)->group(static function () use ($can): void {
                    Route::get('pages', [PagesController::class, 'index'])->middleware($can(TokenAction::Read))->name('pages.index');
                    Route::get('pages/{page}', [PagesController::class, 'show'])->middleware($can(TokenAction::Read))->whereNumber('page')->name('pages.show');
                    Route::get('types/{type}/fields', TypeFieldsController::class)->middleware($can(TokenAction::Read))->name('types.fields');
                    Route::get('locales', LocalesController::class)->middleware($can(TokenAction::Read))->name('locales');

                    Route::post('pages', [PagesController::class, 'store'])->middleware($can(TokenAction::Create))->name('pages.store');
                    Route::patch('pages/{page}', [PagesController::class, 'update'])->middleware($can(TokenAction::Update))->whereNumber('page')->name('pages.update');
                    Route::delete('pages/{page}', [PagesController::class, 'destroy'])->middleware($can(TokenAction::Delete))->whereNumber('page')->name('pages.destroy');
                    Route::post('pages/{page}/publish', [PagesController::class, 'publish'])->middleware($can(TokenAction::Publish))->whereNumber('page')->name('pages.publish');
                    Route::post('media', MediaController::class)->middleware($can(TokenAction::Media))->name('media.store');
                });

                Route::middleware(RequiresCmsRole::class . ':' . self::REDIRECTS_ROLE)->group(static function (): void {
                    $site = static fn (SiteAbility $ability): string => RequiresSiteAbility::class . ':' . $ability->value;

                    Route::get('not-found', [NotFoundController::class, 'index'])->middleware($site(SiteAbility::NotFoundRead))->name('not-found.index');
                    Route::post('not-found/{hit}/ignore', [NotFoundController::class, 'ignore'])->middleware($site(SiteAbility::NotFoundWrite))->whereNumber('hit')->name('not-found.ignore');
                    Route::post('not-found/{hit}/unignore', [NotFoundController::class, 'unignore'])->middleware($site(SiteAbility::NotFoundWrite))->whereNumber('hit')->name('not-found.unignore');
                    Route::get('redirects', [RedirectsController::class, 'index'])->middleware($site(SiteAbility::RedirectsRead))->name('redirects.index');
                    Route::post('redirects', [RedirectsController::class, 'store'])->middleware($site(SiteAbility::RedirectsWrite))->name('redirects.store');
                    Route::delete('redirects/{redirect}', [RedirectsController::class, 'destroy'])->middleware($site(SiteAbility::RedirectsWrite))->whereNumber('redirect')->name('redirects.destroy');
                });
            });
    }
}
