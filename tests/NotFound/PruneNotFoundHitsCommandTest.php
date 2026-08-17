<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function notFoundHitAttributes(string $path, string $lastSeenAt): array
{
    return [
        'locale' => 'en',
        'path' => $path,
        'path_hash' => hash('sha256', $path),
        'hits' => 1,
        'first_seen_at' => $lastSeenAt,
        'last_seen_at' => $lastSeenAt,
        'is_ignored' => false,
    ];
}

it('deletes only rows older than the retention window', function (): void {
    app(Settings::class)->set('seo.not_found.retention_days', 30);

    NotFoundHit::query()->create(notFoundHitAttributes('old', now()->subDays(31)->toDateTimeString()));
    NotFoundHit::query()->create(notFoundHitAttributes('recent', now()->subDays(10)->toDateTimeString()));

    $this->artisan('cms:not-found:prune')->assertExitCode(0);

    expect(NotFoundHit::query()->pluck('path')->all())->toBe(['recent']);
});

it('defaults to a 90-day retention window when unset', function (): void {
    NotFoundHit::query()->create(notFoundHitAttributes('borderline-old', now()->subDays(91)->toDateTimeString()));
    NotFoundHit::query()->create(notFoundHitAttributes('borderline-new', now()->subDays(89)->toDateTimeString()));

    $this->artisan('cms:not-found:prune')->assertExitCode(0);

    expect(NotFoundHit::query()->pluck('path')->all())->toBe(['borderline-new']);
});

it('falls back to 90 days when the stored retention is null or zero, instead of wiping the log', function (mixed $stored): void {
    // The settings form saved with a blank field stores null; Settings::get()
    // only applies its default for a MISSING key, and (int) null is a 0-day
    // retention — which, now that the command is scheduled daily, would
    // silently wipe the whole log every night.
    app(Settings::class)->set('seo.not_found.retention_days', $stored);

    NotFoundHit::query()->create(notFoundHitAttributes('recent', now()->subDays(10)->toDateTimeString()));
    NotFoundHit::query()->create(notFoundHitAttributes('ancient', now()->subDays(91)->toDateTimeString()));

    $this->artisan('cms:not-found:prune')->assertExitCode(0);

    expect(NotFoundHit::query()->pluck('path')->all())->toBe(['recent']);
})->with([
    'null' => [null],
    'zero' => [0],
    'empty string' => [''],
]);
