<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\Http\Middleware\SetLanguage;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function seedErrorPage(ErrorClass $class, array $content = []): Page
{
    return Page::factory()->create([
        'is_system' => true,
        'error_class' => $class,
        'status' => PageStatus::Published,
        'is_indexable' => false,
        'name' => ['en' => $class->value . ' page'],
        'heading' => ['en' => $class->value . ' heading'],
        'content' => $content,
    ]);
}

/**
 * frontendRoutes()'s catch-all matches every path not already claimed, so an
 * extra test route only wins if it's declared first — the same ordering
 * rule real hosts follow (see docs/host/ai/wiring.md, "Call it last").
 */
function withExtraFrontendRoute(Closure $registerFirst): void
{
    $registerFirst();
    app(Cms::class)->frontendRoutes();
}

it('publishes no JSON-LD and no breadcrumbs on the 404 page', function (): void {
    Page::factory()->create(['is_system' => true, 'status' => PageStatus::Published, 'name' => ['en' => 'Home']]);
    seedErrorPage(ErrorClass::ClientError);
    app(Cms::class)->frontendRoutes();

    $this->get('/nonexistent', ['X-Inertia' => 'true'])
        ->assertNotFound()
        ->assertJsonPath('props.seo.jsonLd', [])
        ->assertJsonPath('props.breadcrumbs', []);
});

// Contract 1
it('renders the 404 page from the DB through the normal Cms/Page pipeline, noindex and no canonical', function (): void {
    seedErrorPage(ErrorClass::ClientError, [
        ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Not found here']]],
    ]);
    app(Cms::class)->frontendRoutes();

    $this->get('/nonexistent', ['X-Inertia' => 'true'])
        ->assertNotFound()
        ->assertJsonPath('component', 'Cms/Page')
        ->assertJsonPath('props.render.elements.hero-1.props.title', 'Not found here')
        ->assertJsonPath('props.seo.noindex', true)
        ->assertJsonPath('props.seo.canonical', null);
});

it('renders the 404 page in a non-default locale', function (): void {
    Language::factory()->create(['locale' => 'ru', 'is_frontend_active' => true]);
    seedErrorPage(ErrorClass::ClientError, [
        ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Not found', 'ru' => 'Ne naideno']]],
    ]);
    app(Cms::class)->frontendRoutes();

    $this->get('/ru/nonexistent', ['X-Inertia' => 'true'])
        ->assertNotFound()
        ->assertJsonPath('component', 'Cms/Page')
        ->assertJsonPath('props.render.elements.hero-1.props.title', 'Ne naideno')
        ->assertJsonPath('props.seo.noindex', true)
        ->assertJsonPath('props.seo.canonical', null);
});

// Contract 2
it('cannot unpublish the 4xx page through the model', function (): void {
    $page = seedErrorPage(ErrorClass::ClientError);

    expect(fn () => $page->update(['status' => 'draft']))->toThrow(DomainException::class);
});

it('falls back to an empty render with the lang-file heading when the 4xx page row is missing', function (): void {
    app(Cms::class)->frontendRoutes();

    $this->get('/nonexistent', ['X-Inertia' => 'true'])
        ->assertNotFound()
        ->assertJsonPath('component', 'Cms/Page')
        ->assertJsonPath('props.render', [])
        ->assertJsonPath('props.page.heading', __('tbtop-cms::errors.4xx.heading'));
});

it('gives the fallback 404 the same seo keys as a normal page', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    app(Cms::class)->frontendRoutes();

    $pageSeo = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->json('props.seo');
    $fallbackSeo = $this->get('/nonexistent', ['X-Inertia' => 'true'])->assertNotFound()->json('props.seo');

    $pageKeys = array_keys($pageSeo);
    $fallbackKeys = array_keys($fallbackSeo);
    sort($pageKeys);
    sort($fallbackKeys);

    expect($fallbackKeys)->toBe($pageKeys)
        ->and($fallbackSeo['noindex'])->toBeTrue();
});

// Contract 3
it('still logs a NotFoundHit and lets a redirect win over the 404 page', function (): void {
    seedErrorPage(ErrorClass::ClientError);
    app(Cms::class)->frontendRoutes();

    $this->get('/totally-missing', ['X-Inertia' => 'true'])->assertNotFound();

    expect(NotFoundHit::query()->where('path', 'totally-missing')->exists())->toBeTrue();

    Redirect::create(['locale' => 'en', 'from_path' => 'old-path', 'to_path' => 'new-path', 'status_code' => 301]);

    $this->get('/old-path')->assertRedirect('/new-path');
});

// Contract 4 — a whitelisted 4xx raised by abort() from an ordinary host
// route (not the catch-all's own not-found path) still renders the same
// error page; a non-whitelisted status (403 isn't in the default list)
// is left to fall through to Laravel's stock handling.
it('renders the 4xx page for abort(404) raised from a host route in the frontend group', function (): void {
    seedErrorPage(ErrorClass::ClientError, [
        ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Client error']]],
    ]);

    withExtraFrontendRoute(function (): void {
        Route::middleware(['web', SetLanguage::class])->group(function (): void {
            Route::get('_test/missing-thing', fn () => abort(404));
        });
    });

    $this->get('/_test/missing-thing', ['X-Inertia' => 'true'])
        ->assertNotFound()
        ->assertJsonPath('component', 'Cms/Page')
        ->assertJsonPath('props.render.elements.hero-1.props.title', 'Client error');
});

it('leaves a JSON client its stock Laravel error body', function (): void {
    seedErrorPage(ErrorClass::ClientError);

    withExtraFrontendRoute(function (): void {
        Route::middleware(['web', SetLanguage::class])->group(function (): void {
            Route::get('_test/missing-json', fn () => abort(404));
        });
    });

    $this->getJson('/_test/missing-json')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonMissingPath('component');
});

// The catch-all route is the main source of 404s, and it reaches the error
// page by throwing rather than rendering directly — so the JSON/config gates
// in CmsServiceProvider::renderError() apply to it too. Mutation this catches:
// PageController::notFound() returning ErrorResponse::render() itself would
// hand a JSON client an HTML CMS page, bypassing every gate.
it('leaves a JSON client its stock error body on the catch-all 404 too', function (): void {
    seedErrorPage(ErrorClass::ClientError);
    app(Cms::class)->frontendRoutes();

    $this->getJson('/nonexistent')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonMissingPath('component');
});

it('leaves the catch-all 404 to stock Laravel when errors.handle is false', function (): void {
    config(['tbtop-cms.errors.handle' => false]);
    seedErrorPage(ErrorClass::ClientError);
    app(Cms::class)->frontendRoutes();

    // Raised, not rendered: with the renderable opted out there is nothing to
    // convert the 404 into a CMS page, so it stays an ordinary
    // NotFoundHttpException for the host's own handler to deal with.
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/nonexistent', ['X-Inertia' => 'true']))
        ->toThrow(NotFoundHttpException::class);
});

it('leaves a non-whitelisted status like 403 to stock Laravel handling', function (): void {
    seedErrorPage(ErrorClass::ClientError);

    withExtraFrontendRoute(function (): void {
        Route::middleware(['web', SetLanguage::class])->group(function (): void {
            Route::get('_test/forbidden', fn () => abort(403));
        });
    });

    $response = $this->get('/_test/forbidden');

    $response->assertForbidden();
    expect($response->headers->get('Content-Type'))->not->toContain('application/json');
});

it('renders the 5xx page for abort(500) with app.debug off', function (): void {
    config(['app.debug' => false]);
    seedErrorPage(ErrorClass::ServerError, [
        ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Server error']]],
    ]);

    withExtraFrontendRoute(function (): void {
        Route::middleware(['web', SetLanguage::class])->group(function (): void {
            Route::get('_test/boom', fn () => abort(500));
        });
    });

    $this->get('/_test/boom', ['X-Inertia' => 'true'])
        ->assertServerError()
        ->assertJsonPath('component', 'Cms/Page')
        ->assertJsonPath('props.render.elements.hero-1.props.title', 'Server error');
});

it('does not intercept a 5xx when app.debug is on', function (): void {
    config(['app.debug' => true]);
    seedErrorPage(ErrorClass::ServerError);

    withExtraFrontendRoute(function (): void {
        Route::middleware(['web', SetLanguage::class])->group(function (): void {
            Route::get('_test/boom', fn () => abort(500));
        });
    });

    $this->withoutExceptionHandling();
    $this->expectException(HttpException::class);
    $this->get('/_test/boom');
});

it('stays stock Laravel under the admin prefix', function (): void {
    withExtraFrontendRoute(function (): void {
        Route::middleware(['web'])->group(function (): void {
            Route::get(config('tbtop-cms.admin.prefix', 'admin') . '/_test/missing', fn () => abort(404));
        });
    });

    $response = $this->get('/admin/_test/missing');

    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->not->toContain('application/json');
});

// Contract 5
it('registers nothing when errors.handle is false, but Cms::errorResponse still works directly', function (): void {
    config(['tbtop-cms.errors.handle' => false]);
    seedErrorPage(ErrorClass::ClientError);

    withExtraFrontendRoute(function (): void {
        Route::middleware(['web'])->group(function (): void {
            Route::get('_test/plain-404', fn () => abort(404));
        });
    });

    // Not intercepted: stock Laravel error response, not a Cms/Page render.
    $plain = $this->get('/_test/plain-404');
    $plain->assertNotFound();
    expect($plain->headers->get('Content-Type'))->not->toContain('application/json');

    $directRequest = Request::create('/whatever');
    $directRequest->headers->set('X-Inertia', 'true');

    $response = app(Cms::class)->errorResponse($directRequest, 404);
    expect($response->getStatusCode())->toBe(404);
});

// Contract 6
it('falls back to the static Blade view when rendering the 5xx page itself throws', function (): void {
    config(['app.debug' => false]);
    seedErrorPage(ErrorClass::ServerError);

    $this->app->bind(BlockContentResolver::class, function (): BlockContentResolver {
        return new class extends BlockContentResolver
        {
            public function __construct() {}

            public function resolve(array $content, ?Page $page = null, ?object $source = null): array
            {
                throw new RuntimeException('resolver is down');
            }
        };
    });

    $response = app(Cms::class)->errorResponse(request(), 500);

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())->toContain(__('tbtop-cms::errors.5xx.heading'));
});
