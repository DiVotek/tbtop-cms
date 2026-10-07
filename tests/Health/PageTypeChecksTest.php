<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\ConcertPageType;
use Tbtop\Cms\Tests\Fixtures\EventPageType;
use Tbtop\Cms\Tests\Fixtures\OrphanSubtypePageType;
use Tbtop\Cms\Tests\Fixtures\ShadowingSubtypePageType;
use Tbtop\Cms\Tests\Fixtures\TourConcertPageType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PageTypeRegistry::extend(fn (): array => [EventPageType::class, ConcertPageType::class]);
});

/** Written past Eloquent, the way an old seeder or a raw import would, so PageObserver never sees it. */
function rawPage(string $type, ?string $subtype): int
{
    return DB::table((new Page)->getTable())->insertGetId([
        'type' => $type, 'subtype' => $subtype, 'status' => PageStatus::Draft->value, 'name' => json_encode(['en' => 'Raw']),
    ]);
}

it('lists through cms:doctor the stored pages whose type is a subtype or whose subtype is not one of their type\'s', function (): void {
    Page::create(['type' => 'event', 'subtype' => 'concert', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);
    Page::create(['type' => 'event', 'status' => PageStatus::Draft, 'name' => ['en' => 'Fair']]);
    $asType = [rawPage('concert', null), rawPage('concert', null)];
    $foreign = rawPage('page', 'concert');
    $retired = rawPage('event', 'retired');
    Page::query()->findOrFail($retired)->delete();

    $this->artisan('cms:doctor')->expectsOutputToContain(
        "type=concert: 2 (#{$asType[0]}, #{$asType[1]}); type=event subtype=retired: 1 (#{$retired}); type=page subtype=concert: 1 (#{$foreign})."
    );
});

it('passes when every stored type and subtype pair is valid', function (): void {
    Page::create(['type' => 'event', 'subtype' => 'concert', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);

    $this->artisan('cms:doctor')
        ->expectsOutputToContain(__('tbtop-cms::health.checks.page_type_pairs.ok'));
});

it('names through cms:doctor each subtype whose base is unregistered or is itself a subtype', function (): void {
    PageTypeRegistry::extend(fn (): array => [OrphanSubtypePageType::class, TourConcertPageType::class]);

    $this->artisan('cms:doctor')->expectsOutputToContain('orphan → ghost; tour_concert → concert.');
});

it('passes when every subtype names a registered top-level base', function (): void {
    $this->artisan('cms:doctor')
        ->expectsOutputToContain(__('tbtop-cms::health.checks.page_subtype_bases.ok'));
});

it('names through cms:doctor a subtype back-reference that shares its name with a base type field', function (): void {
    PageTypeRegistry::extend(fn (): array => [ShadowingSubtypePageType::class]);

    $this->artisan('cms:doctor')->expectsOutputToContain('(type: names): shadowing: venue.');
});

it('passes when no back-reference is named like a field', function (): void {
    $this->artisan('cms:doctor')
        ->expectsOutputToContain(__('tbtop-cms::health.checks.page_field_collisions.ok'));
});
