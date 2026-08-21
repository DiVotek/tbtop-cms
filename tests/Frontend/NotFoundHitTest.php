<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\NotFound\Events\PageNotFound;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

/**
 * Application::terminate() (Kernel::terminate() calls it after every test
 * client request) runs every queued terminating callback but never clears
 * the queue — harmless in production (the process exits right after), but
 * in a single test making several simulated requests against one long-lived
 * app instance, a later terminate() replays every earlier request's
 * callback too. Clearing the queue after each simulated request restores
 * the real one-request-per-process boundary this listener relies on.
 */
function clearTerminatingCallbacks(): void
{
    $property = new ReflectionProperty(app(), 'terminatingCallbacks');
    $property->setValue(app(), []);
}

it('records one row with hits = 1 on a miss to an unknown path', function (): void {
    // The after-response fan-out (app()->terminating()) has already run by
    // the time get() returns — MakesHttpRequests::call() invokes
    // $kernel->terminate() itself, same as a real request.
    $this->get('/totally/unknown/path')->assertNotFound();

    $hit = NotFoundHit::query()->first();

    expect($hit)->not->toBeNull()
        ->and($hit->path)->toBe('totally/unknown/path')
        ->and($hit->locale)->toBe('en')
        ->and($hit->hits)->toBe(1)
        ->and($hit->is_ignored)->toBeFalse();
});

it('increments the same row to hits = 2 on a repeat miss instead of inserting a second row', function (): void {
    $this->get('/missing')->assertNotFound();
    clearTerminatingCallbacks();
    $this->get('/missing')->assertNotFound();

    expect(NotFoundHit::query()->count())->toBe(1)
        ->and(NotFoundHit::query()->first()->hits)->toBe(2);
});

it('stores long locale identifiers without truncating or merging them', function (): void {
    event(new PageNotFound('missing', 'zh-Hant', null, null));
    event(new PageNotFound('missing', 'sr-Latn', null, null));
    app()->terminate();

    expect(NotFoundHit::query()->orderBy('locale')->pluck('locale')->all())
        ->toBe(['sr-Latn', 'zh-Hant']);
});

it('does not log a path that resolves to a redirect', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'old', 'to_path' => 'new', 'status_code' => 301]);

    $this->get('/old')->assertRedirect('/new');

    expect(NotFoundHit::query()->count())->toBe(0);
});

it('does not log a page that actually resolves', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])->assertOk();

    expect(NotFoundHit::query()->count())->toBe(0);
});

it('skips a path matching the scanner-noise ignore list', function (): void {
    $this->get('/wp-login.php')->assertNotFound();

    expect(NotFoundHit::query()->count())->toBe(0);
});

it('records nothing when logging is turned off in Settings', function (): void {
    app(Settings::class)->set('seo.not_found.enabled', false);

    $this->get('/missing')->assertNotFound();

    expect(NotFoundHit::query()->count())->toBe(0);
});

it('keeps recording when the setting row holds a stored null', function (): void {
    // Settings::get() applies its default only for a missing KEY, so a row
    // decoding to null must not read as "off" — an untouched toggle would
    // otherwise silently stop the log.
    app(Settings::class)->set('seo.not_found.enabled', null);

    $this->get('/missing')->assertNotFound();

    expect(NotFoundHit::query()->count())->toBe(1);
});
