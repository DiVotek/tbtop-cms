<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['cache.default' => 'array']);
    RateLimiter::clear('a@example.com|127.0.0.1');
});

function submitLoginForm(array $payload): TestResponse
{
    return test()->post('/admin/login/forms/login', $payload);
}

function makeLoginableAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'correct-password']);
    CmsUserProfile::create(['user_id' => $user->id]);

    return $user;
}

it('authenticates and redirects on valid credentials', function (): void {
    makeLoginableAdmin();

    submitLoginForm(['email' => 'a@example.com', 'password' => 'correct-password'])->assertRedirect();

    expect(auth('web')->check())->toBeTrue();
});

it('rejects an invalid password without authenticating', function (): void {
    makeLoginableAdmin();

    submitLoginForm(['email' => 'a@example.com', 'password' => 'wrong'])->assertInvalid(['email']);

    expect(auth('web')->check())->toBeFalse();
});

it('locks out further attempts after 5 failed logins from the same email and ip', function (): void {
    makeLoginableAdmin();

    for ($i = 0; $i < 5; $i++) {
        submitLoginForm(['email' => 'a@example.com', 'password' => 'wrong'])->assertInvalid(['email']);
    }

    // The 6th attempt is throttled even though nothing about the request changed.
    submitLoginForm(['email' => 'a@example.com', 'password' => 'wrong'])->assertInvalid(['email']);

    expect(RateLimiter::tooManyAttempts('a@example.com|127.0.0.1', 5))->toBeTrue();
});

it('still throttles a correct password once the failure threshold is hit', function (): void {
    $user = makeLoginableAdmin();

    for ($i = 0; $i < 5; $i++) {
        submitLoginForm(['email' => 'a@example.com', 'password' => 'wrong'])->assertInvalid(['email']);
    }

    submitLoginForm(['email' => 'a@example.com', 'password' => 'correct-password'])->assertInvalid(['email']);

    expect(auth('web')->check())->toBeFalse()
        ->and(CmsUserProfile::where('user_id', $user->id)->exists())->toBeTrue();
});

it('clears the failure count on a successful login', function (): void {
    makeLoginableAdmin();

    submitLoginForm(['email' => 'a@example.com', 'password' => 'wrong'])->assertInvalid(['email']);
    submitLoginForm(['email' => 'a@example.com', 'password' => 'wrong'])->assertInvalid(['email']);
    submitLoginForm(['email' => 'a@example.com', 'password' => 'correct-password'])->assertRedirect();

    expect(RateLimiter::attempts('a@example.com|127.0.0.1'))->toBe(0);
});
