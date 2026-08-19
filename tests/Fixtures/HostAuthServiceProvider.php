<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for a host that ships its own auth — a Laravel starter kit,
 * Fortify, Breeze — by declaring the `login` route those hosts own.
 *
 * The route is declared from register(), not boot(): Testbench appends test
 * providers after the package's own, so booting here would register this
 * route after the one under test and invert the ordering a real host has,
 * where routes/web.php is loaded before any package provider boots.
 */
final class HostAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Route::get('login', fn (): string => 'host login page')->name('login');
    }
}
