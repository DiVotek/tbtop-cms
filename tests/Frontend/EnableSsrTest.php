<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

it('marks the request disabled without mutating global inertia config when the cms flag is off', function (): void {
    config(['tbtop-cms.ssr.enabled' => false, 'inertia.ssr.enabled' => false]);

    $seenInside = null;
    (new EnableSsr)->handle(request(), function ($request) use (&$seenInside) {
        $seenInside = $request->attributes->get(EnableSsr::REQUEST_ATTRIBUTE);

        return response('ok');
    });

    expect($seenInside)->toBeFalse()
        ->and(config('inertia.ssr.enabled'))->toBeFalse();
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

it('marks only the current request for ssr without mutating global inertia config', function (): void {
    config(['tbtop-cms.ssr.enabled' => true, 'inertia.ssr.enabled' => false]);
    $seenInside = null;

    (new EnableSsr)->handle(request(), function ($request) use (&$seenInside) {
        $seenInside = $request->attributes->get(EnableSsr::REQUEST_ATTRIBUTE);

        return response('ok');
    });

    // Config is application-global: on a long-lived worker a raised flag
    // left behind would SSR a later admin request in the same process
    // (bot-reported). The flag must be true only while $next() runs.
    expect($seenInside)->toBeTrue()
        ->and(config('inertia.ssr.enabled'))->toBeFalse();
});

it('keeps overlapping request decisions isolated', function (): void {
    config(['tbtop-cms.ssr.enabled' => true, 'inertia.ssr.enabled' => false]);
    $cmsRequest = Request::create('/cms');
    $otherRequest = Request::create('/other');

    (new EnableSsr)->handle($cmsRequest, function () use ($cmsRequest, $otherRequest) {
        config(['tbtop-cms.ssr.enabled' => false]);

        (new EnableSsr)->handle($otherRequest, fn () => response('other'));

        expect($cmsRequest->attributes->get(EnableSsr::REQUEST_ATTRIBUTE))->toBeTrue()
            ->and($otherRequest->attributes->get(EnableSsr::REQUEST_ATTRIBUTE))->toBeFalse()
            ->and(config('inertia.ssr.enabled'))->toBeFalse();

        return response('cms');
    });
});

it('does not mutate global inertia config when the downstream pipeline throws', function (): void {
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

it('applies EnableSsr to cms frontend routes without flipping global config', function (): void {
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
