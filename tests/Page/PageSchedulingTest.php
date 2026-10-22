<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Page\Enums\PageDisplayState;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

it('is live when published with no schedule dates', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published]);

    expect($page->isLive())->toBeTrue()
        ->and($page->displayState())->toBe(PageDisplayState::Published)
        ->and(Page::query()->live()->whereKey($page->id)->exists())->toBeTrue();
});

it('is not live when published_at is in the future', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'published_at' => now()->addHour(),
    ]);

    expect($page->isLive())->toBeFalse()
        ->and($page->displayState())->toBe(PageDisplayState::Scheduled)
        ->and(Page::query()->live()->whereKey($page->id)->exists())->toBeFalse();
});

it('is live when published_at is exactly now', function (): void {
    $now = now();
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'published_at' => $now,
    ]);

    $this->travelTo($now, function () use ($page): void {
        expect($page->fresh()->isLive())->toBeTrue()
            ->and($page->fresh()->displayState())->toBe(PageDisplayState::Published)
            ->and(Page::query()->live()->whereKey($page->id)->exists())->toBeTrue();
    });
});

it('is live when published_at is in the past', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'published_at' => now()->subHour(),
    ]);

    expect($page->isLive())->toBeTrue()
        ->and(Page::query()->live()->whereKey($page->id)->exists())->toBeTrue();
});

it('is not live when unpublish_at is in the past', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'unpublish_at' => now()->subHour(),
    ]);

    expect($page->isLive())->toBeFalse()
        ->and($page->displayState())->toBe(PageDisplayState::Expired)
        ->and(Page::query()->live()->whereKey($page->id)->exists())->toBeFalse();
});

it('is live when unpublish_at is in the future', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'unpublish_at' => now()->addHour(),
    ]);

    expect($page->isLive())->toBeTrue()
        ->and(Page::query()->live()->whereKey($page->id)->exists())->toBeTrue();
});

it('is never live as a draft, regardless of schedule dates', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Draft,
        'published_at' => now()->subHour(),
        'unpublish_at' => now()->addHour(),
    ]);

    expect($page->isLive())->toBeFalse()
        ->and($page->displayState())->toBe(PageDisplayState::Draft)
        ->and(Page::query()->live()->whereKey($page->id)->exists())->toBeFalse();
});

it('stamps published_at with now when a page is first published without a date', function (): void {
    $this->travelTo(Carbon::parse('2026-03-01 09:00:00'));
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'About']]);

    expect($page->published_at)->toBeNull();

    $page->update(['status' => PageStatus::Published]);

    expect($page->refresh()->published_at?->toDateTimeString())->toBe('2026-03-01 09:00:00');
});

it('stamps published_at when a page is created already published without a date', function (): void {
    $this->travelTo(Carbon::parse('2026-03-01 09:00:00'));

    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    expect($page->refresh()->published_at?->toDateTimeString())->toBe('2026-03-01 09:00:00');
});

it('keeps a date the editor scheduled when the page is published', function (): void {
    $this->travelTo(Carbon::parse('2026-03-01 09:00:00'));
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'published_at' => Carbon::parse('2026-04-01 12:00:00'), 'name' => ['en' => 'About']]);

    $page->update(['status' => PageStatus::Published]);

    expect($page->refresh()->published_at?->toDateTimeString())->toBe('2026-04-01 12:00:00')
        ->and($page->displayState())->toBe(PageDisplayState::Scheduled);
});

it('keeps the first publish time when a page is unpublished and published again', function (): void {
    $this->travelTo(Carbon::parse('2026-03-01 09:00:00'));
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'About']]);
    $page->update(['status' => PageStatus::Published]);

    $this->travelTo(Carbon::parse('2026-03-05 18:00:00'));
    $page->update(['status' => PageStatus::Draft]);
    $page->update(['status' => PageStatus::Published]);

    expect($page->refresh()->published_at?->toDateTimeString())->toBe('2026-03-01 09:00:00');
});

it('leaves published_at empty when a page that was never published is saved as a draft', function (): void {
    $this->travelTo(Carbon::parse('2026-03-01 09:00:00'));
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'About']]);

    $page->update(['name' => ['en' => 'Renamed']]);

    expect($page->refresh()->published_at)->toBeNull();
});

it('does not stamp an already-published page that has no date when it is edited', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $page->forceFill(['published_at' => null])->saveQuietly();

    $this->travelTo(Carbon::parse('2026-03-01 09:00:00'));
    $page->update(['name' => ['en' => 'Renamed']]);

    expect($page->refresh()->published_at)->toBeNull();
});
