<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tbtop\Cms\Admin\Enums\CmsRole;
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
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);

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

it('applies the profile locale to the admin panel right after login', function (): void {
    $user = AdminUser::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'correct-password']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator, 'locale' => 'uk']);

    submitLoginForm(['email' => 'a@example.com', 'password' => 'correct-password'])->assertRedirect();

    test()->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk();
    expect(app()->getLocale())->toBe('uk');
});

it('keeps the default locale when the profile has none set', function (): void {
    makeLoginableAdmin();

    submitLoginForm(['email' => 'a@example.com', 'password' => 'correct-password'])->assertRedirect();

    expect(session('tbtop.locale'))->toBeNull();
});

it('carries the page title and a single breadcrumb from translations', function (): void {
    $response = test()->get('/admin/login', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::login.title'))
        ->and($response->json('props.breadcrumbs'))->toBe([
            ['label' => __('tbtop-cms::login.title')],
        ]);
});

it('labels fields from translations and does not show the sign-in title in the card body', function (): void {
    $structure = test()->get('/admin/login', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    // The "Sign in" title line was dropped from the header (it duplicated the
    // subtitle and the button); only the browser <title> still carries it.
    $texts = loginCollectByKind($structure, 'displayText');
    $titleLines = array_values(array_filter($texts, static fn (array $t): bool => ($t['options']['content'] ?? null) === __('tbtop-cms::login.title')));
    expect($titleLines)->toHaveCount(0);

    $labels = loginFieldLabels($structure);
    expect($labels['email'])->toBe(__('tbtop-cms::login.fields.email'))
        ->and($labels['password'])->toBe(__('tbtop-cms::login.fields.password'))
        ->and($labels['remember'])->toBe(__('tbtop-cms::login.fields.remember'));
});

it('renders the sign-in button labeled from translations', function (): void {
    $structure = test()->get('/admin/login', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $actions = loginCollectByKind($structure, 'action');
    $signIn = collect($actions)->firstWhere('name', 'signin');

    expect($signIn)->not->toBeNull()
        ->and($signIn['options']['label'] ?? null)->toBe(__('tbtop-cms::login.actions.sign_in'));
});

// Regression: "remember me" must stay a checkbox, matching login-form
// convention — not a boolean field (which renders as a switch).
it('keeps remember-me a checkbox, not a boolean switch', function (): void {
    $structure = test()->get('/admin/login', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $remember = loginFindFieldByName($structure, 'remember');
    expect($remember)->not->toBeNull()
        ->and($remember['kind'])->toBe('checkbox');
});

it('renders the configured panel brand in the card', function (): void {
    $structure = test()->get('/admin/login', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $texts = loginCollectByKind($structure, 'displayText');
    $brand = array_values(array_filter($texts, static fn (array $t): bool => ($t['options']['variant'] ?? null) === 'heading'));

    expect($brand)->toHaveCount(1)
        ->and($brand[0]['options']['content'] ?? null)->toBe('TableTop CMS');
});

it('rejects an invalid password with the cms-owned translation, not the host app key', function (): void {
    makeLoginableAdmin();

    $response = submitLoginForm(['email' => 'a@example.com', 'password' => 'wrong'])->assertInvalid(['email']);

    $message = __('tbtop-cms::login.errors.failed');
    expect($message)->not->toBe('tbtop-cms::login.errors.failed');
    $response->assertSessionHasErrors(['email' => $message]);
});

it('throttles the 6th failed attempt with the cms-owned translation', function (): void {
    makeLoginableAdmin();

    for ($i = 0; $i < 5; $i++) {
        submitLoginForm(['email' => 'a@example.com', 'password' => 'wrong'])->assertInvalid(['email']);
    }

    $response = submitLoginForm(['email' => 'a@example.com', 'password' => 'wrong'])->assertInvalid(['email']);

    $seconds = RateLimiter::availableIn('a@example.com|127.0.0.1');
    $message = __('tbtop-cms::login.errors.throttle', ['seconds' => $seconds]);
    expect($message)->not->toBe('tbtop-cms::login.errors.throttle');
    $response->assertSessionHasErrors(['email' => $message]);
});

it('links back to the site below the card', function (): void {
    $structure = test()->get('/admin/login', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $htmlBlocks = loginCollectByKind($structure, 'displayHtml');
    $links = array_values(array_filter($htmlBlocks, static fn (array $b): bool => str_contains((string) ($b['options']['html'] ?? ''), 'href="' . url('/') . '"')));

    expect($links)->toHaveCount(1)
        ->and($links[0]['options']['html'])->toContain(e(__('tbtop-cms::login.links.site')));
});

it('renders the product mark and a footer with copyright and version', function (): void {
    $structure = test()->get('/admin/login', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $htmlBlocks = loginCollectByKind($structure, 'displayHtml');
    $marks = array_values(array_filter($htmlBlocks, static fn (array $b): bool => str_contains((string) ($b['options']['html'] ?? ''), '<svg')));
    expect($marks)->toHaveCount(1);

    $texts = loginCollectByKind($structure, 'displayText');
    $footer = array_values(array_filter($texts, static fn (array $t): bool => str_starts_with((string) ($t['options']['content'] ?? ''), 'TableTop CMS ©')));
    expect($footer)->toHaveCount(1)
        ->and($footer[0]['options']['content'])->toContain(date('Y'));
});

/** @param  array<string, mixed>  $node @return array<string, mixed>|null */
function loginFindFieldByName(array $node, string $name): ?array
{
    if (($node['name'] ?? null) === $name) {
        return $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            $found = loginFindFieldByName($child, $name);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $node
 * @return array<string, string>
 */
function loginFieldLabels(array $node): array
{
    $labels = [];
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (! is_array($child)) {
            continue;
        }
        if (isset($child['name'], $child['options']['label'])) {
            $labels[$child['name']] = $child['options']['label'];
        }
        $labels = [...$labels, ...loginFieldLabels($child)];
    }

    return $labels;
}

/**
 * @param  array<string, mixed>  $node
 * @return list<array<string, mixed>>
 */
function loginCollectByKind(array $node, string $kind): array
{
    $out = [];
    if (($node['kind'] ?? null) === $kind) {
        $out[] = $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            $out = [...$out, ...loginCollectByKind($child, $kind)];
        }
    }

    return $out;
}
