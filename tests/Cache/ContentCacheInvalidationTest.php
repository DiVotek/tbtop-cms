<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Form\Events\SubmissionReceived;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Language\Events\LanguagesChanged;
use Tbtop\Cms\Layout\Events\LayoutSaved;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Events\PagePublished;
use Tbtop\Cms\Page\Events\PageRestored;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Events\PageUnpublished;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Events\SettingsSaved;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.cache.enabled' => true]);
});

/** Reads the same "does this key still resolve to the old value" signal every case below asserts. */
function primeAndReturnStaleness(Closure $dispatch): bool
{
    $cache = app(CmsCache::class);
    $cache->remember('probe', fn (): string => 'before');

    $dispatch();

    $after = app(CmsCache::class)->remember('probe', fn (): string => 'after');

    return $after === 'before';
}

dataset('bumping events', [
    'PageSaved' => [fn () => new PageSaved(Page::factory()->make(), true)],
    'PagePublished' => [fn () => new PagePublished(Page::factory()->make())],
    'PageUnpublished' => [fn () => new PageUnpublished(Page::factory()->make())],
    'PageDeleted' => [fn () => new PageDeleted(Page::factory()->make(), false)],
    'PageRestored' => [fn () => new PageRestored(Page::factory()->make())],
    'LayoutSaved' => [fn () => new LayoutSaved(new Layout(['slot' => 'header']))],
    'SettingsSaved' => [fn () => new SettingsSaved(['site.name'])],
    'LanguagesChanged' => [fn () => new LanguagesChanged],
]);

it('bumps the content cache version on every domain content event', function (Closure $makeEvent): void {
    $stillStale = primeAndReturnStaleness(function () use ($makeEvent): void {
        event($makeEvent());
    });

    expect($stillStale)->toBeFalse();
})->with('bumping events');

it('does not bump the content cache version on SubmissionReceived', function (): void {
    $page = Page::factory()->create();

    $stillStale = primeAndReturnStaleness(function () use ($page): void {
        event(new SubmissionReceived(new FormSubmission, [], $page, 'form-1', 'en'));
    });

    expect($stillStale)->toBeTrue();
});
