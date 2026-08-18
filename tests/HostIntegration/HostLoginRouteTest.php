<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('does not register a second login route when the host already defines one', function (): void {
    $logins = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => $route->getName() === 'login');

    // Two routes named `login` are not merely redundant: Laravel keeps the
    // first for URL generation but dispatches the last match, so the host's
    // login page becomes unreachable while route('login') still points at it.
    expect($logins)->toHaveCount(1);
});

it('leaves the host login page reachable', function (): void {
    $this->get('/login')->assertOk()->assertSee('host login page');
});
