<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response as SsrResponse;
use Tbtop\Cms\Cms;
use Tbtop\Cms\CmsServiceProvider;
use Tbtop\Cms\Frontend\Http\Middleware\EnableSsr;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\RecordingSsrGateway;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Gateway::class, new RecordingSsrGateway);
    RecordingSsrGateway::reset();
    RecordingSsrGateway::$response = new SsrResponse('<title data-inertia="">SSR Title</title>', '<div id="app"></div>');
});

it('leaves inertia.ssr.enabled untouched when the cms flag is off', function (): void {
    config(['tbtop-cms.ssr.enabled' => false, 'inertia.ssr.enabled' => false]);

    (new EnableSsr)->handle(request(), fn ($request) => response('ok'));

    expect(config('inertia.ssr.enabled'))->toBeFalse();
});

// StartSsr::handle() refuses to boot the SSR node process when
// `inertia.ssr.enabled` is false — so the boot-time force-off must mirror the
// cms flag in console instead of forcing false unconditionally, or
// `php artisan inertia:start-ssr` could never start (caught live: the command
// exited with "Inertia SSR is not enabled"). Tests run in console, so
// re-invoking the private boot hook after setting the cms flag reproduces the
// exact state `artisan inertia:start-ssr` boots into.
it('mirrors the cms flag onto inertia.ssr.enabled in console so inertia:start-ssr can boot', function (): void {
    config(['tbtop-cms.ssr.enabled' => true, 'inertia.ssr.enabled' => false]);

    $provider = app()->getProvider(CmsServiceProvider::class);
    (function (): void {
        /** @var CmsServiceProvider $this */
        $this->forceSsrOffByDefault(); // @phpstan-ignore method.private
    })->call($provider);

    expect(config('inertia.ssr.enabled'))->toBeTrue();
});

it('flips inertia.ssr.enabled on for the duration of the request and restores it after', function (): void {
    config(['tbtop-cms.ssr.enabled' => true, 'inertia.ssr.enabled' => false]);
    $seenInside = null;

    (new EnableSsr)->handle(request(), function ($request) use (&$seenInside) {
        $seenInside = config('inertia.ssr.enabled');

        return response('ok');
    });

    // Config is application-global: on a long-lived worker a raised flag
    // left behind would SSR a later admin request in the same process
    // (bot-reported). The flag must be true only while $next() runs.
    expect($seenInside)->toBeTrue()
        ->and(config('inertia.ssr.enabled'))->toBeFalse();
});

it('restores the flag even when the downstream pipeline throws', function (): void {
    config(['tbtop-cms.ssr.enabled' => true, 'inertia.ssr.enabled' => false]);

    expect(fn () => (new EnableSsr)->handle(request(), function ($request): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class)
        ->and(config('inertia.ssr.enabled'))->toBeFalse();
});

// The admin panel (Tbtop\Admin\Http\PageController) always calls
// ->rootView($panel->rootView()) on its Inertia::render(), which — unlike
// Inertia::setRootView() — wins regardless of what a test sets globally.
// That means an admin plain GET always renders the real app.blade.php (@vite
// and all), so it can't reuse server-head-test.blade.php the way cms
// frontend tests do — see ServerHeadTest's docblock for why a plain GET
// through the real view breaks on a fresh CI checkout with no built
// manifest. Every other admin test in this suite avoids the problem the
// same way: X-Inertia requests short-circuit to a JsonResponse before the
// view (and its @vite/@inertiaHead) ever renders — see SectionAbilitiesTest.
// That path alone doesn't prove EnableSsr is absent (a JSON response never
// touches SsrState regardless of middleware), so the two tests below prove
// it a stronger way: EnableSsr is provably absent from the admin route's
// resolved middleware stack, independent of how the response renders.
it('does not register EnableSsr on the admin login route', function (): void {
    $adminRoute = collect(Route::getRoutes())->first(
        fn ($route): bool => str_starts_with((string) $route->uri(), 'admin/login'),
    );

    expect($adminRoute)->not->toBeNull()
        ->and($adminRoute->gatherMiddleware())->not->toContain(EnableSsr::class);
});

it('never calls the SSR gateway for an X-Inertia admin request even when the cms ssr flag is on', function (): void {
    config(['tbtop-cms.ssr.enabled' => true]);

    test()->get('/admin/login', ['X-Inertia' => 'true'])->assertOk();

    expect(RecordingSsrGateway::$calls)->toBe(0);
});

it('applies EnableSsr to cms frontend routes, flipping the config for that request', function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.render.root_view' => 'server-head-test', 'tbtop-cms.ssr.enabled' => true]);
    Language::factory()->default()->create(['locale' => 'en']);
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    app(Cms::class)->frontendRoutes();

    $html = test()->get($page->pathFor('en'))->assertOk()->getContent();

    // The response WAS server-rendered (dispatch happened inside $next(),
    // where Router::prepareResponse renders the Inertia view), yet the
    // global flag is back to false once the request has unwound — an admin
    // request handled next by the same process must not inherit it.
    expect(RecordingSsrGateway::$calls)->toBe(1)
        ->and($html)->toContain('SSR Title')
        ->and(config('inertia.ssr.enabled'))->toBeFalse();
});
