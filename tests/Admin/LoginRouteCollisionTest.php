<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('provides a login redirect when the host defines no login route', function (): void {
    expect(Route::has('login'))->toBeTrue();

    $this->get('/login')->assertRedirect('/admin/login');
});
