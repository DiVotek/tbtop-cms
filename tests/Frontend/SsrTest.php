<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response as SsrResponse;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\RecordingSsrGateway;

uses(RefreshDatabase::class);

// Reuses server-head-test.blade.php (see ServerHeadTest's docblock for why a
// plain GET needs a @vite-free root view on CI, where workbench/public/
// build/manifest.json does not exist).
beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.render.root_view' => 'server-head-test']);
    Language::factory()->default()->create(['locale' => 'en']);
    app()->instance(Gateway::class, new RecordingSsrGateway);
    RecordingSsrGateway::reset();
});

function ssrPublishPage(): Page
{
    return Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);
}

it('renders the SSR head and body and omits the blade fallback tags when SSR is enabled and the gateway responds', function (): void {
    config(['tbtop-cms.ssr.enabled' => true]);
    RecordingSsrGateway::$response = new SsrResponse(
        '<title data-inertia="">SSR Title</title>',
        '<div id="app" data-ssr-marker="body-from-ssr"></div>',
    );
    $page = ssrPublishPage();
    app(Cms::class)->frontendRoutes();

    $html = test()->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain('<title data-inertia="">SSR Title</title>')
        ->toContain('data-ssr-marker="body-from-ssr"')
        ->not->toContain('data-inertia="meta-description"')
        ->not->toContain('data-inertia="og-type"');
    expect(substr_count((string) $html, '<title'))->toBe(1);
});

it('falls back to the blade head and client-render markup when SSR is enabled but the gateway is unreachable', function (): void {
    config(['tbtop-cms.ssr.enabled' => true]);
    RecordingSsrGateway::$response = null;
    $page = ssrPublishPage();
    app(Cms::class)->frontendRoutes();

    $html = test()->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-inertia="og-type"')
        ->toContain('<script data-page="app" type="application/json">')
        ->toContain('<div id="app"></div>');
    expect(substr_count((string) $html, '<title'))->toBe(1);
    expect(substr_count((string) $html, 'data-inertia="og-type"'))->toBe(1);
});

it('never calls the SSR gateway when the cms ssr flag is off, the PR #101 default', function (): void {
    config(['tbtop-cms.ssr.enabled' => false]);
    $page = ssrPublishPage();
    app(Cms::class)->frontendRoutes();

    $html = test()->get($page->pathFor('en'))->assertOk()->getContent();

    expect(RecordingSsrGateway::$calls)->toBe(0)
        ->and($html)->toContain('data-inertia="og-type"');
});

it('dispatches the SSR gateway exactly once per request even though the head include and @inertiaHead both ask for it', function (): void {
    config(['tbtop-cms.ssr.enabled' => true]);
    RecordingSsrGateway::$response = new SsrResponse('<title data-inertia="">SSR Title</title>', '<div id="app"></div>');
    $page = ssrPublishPage();
    app(Cms::class)->frontendRoutes();

    test()->get($page->pathFor('en'))->assertOk();

    expect(RecordingSsrGateway::$calls)->toBe(1);
});

// Inertia's own Ssr\SsrState — the memo this whole design relies on — is
// bound scoped() by the vendor ServiceProvider (not something this package
// binds), which is Laravel's designated "reset between requests under
// Octane/long-lived workers, persist within one request otherwise" binding
// type. Outside Octane (a plain HTTP kernel — what this app runs, and what
// two sequential $this->get() calls exercise here), nothing resets scoped()
// instances between requests in the same PHP process — Laravel's Http\Kernel
// never calls forgetScopedInstances(), only Octane's request-lifecycle
// listener does. Calling it explicitly here simulates that Octane request
// boundary, proving the dispatch count resets per request rather than
// accumulating for the lifetime of the process, without requiring Octane as
// a dependency to prove it.
it('does not leak the dispatch count across two sequential requests in the same process', function (): void {
    config(['tbtop-cms.ssr.enabled' => true]);
    RecordingSsrGateway::$response = new SsrResponse('<title data-inertia="">SSR Title</title>', '<div id="app"></div>');
    $page = ssrPublishPage();
    app(Cms::class)->frontendRoutes();

    test()->get($page->pathFor('en'))->assertOk();
    expect(RecordingSsrGateway::$calls)->toBe(1);

    app()->forgetScopedInstances();
    test()->get($page->pathFor('en'))->assertOk();
    expect(RecordingSsrGateway::$calls)->toBe(2);
});
