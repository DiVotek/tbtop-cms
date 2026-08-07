<?php

declare(strict_types=1);

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
