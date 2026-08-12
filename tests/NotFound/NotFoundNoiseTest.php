<?php

declare(strict_types=1);

use Tbtop\Cms\NotFound\Support\NotFoundNoise;

it('matches a configured pattern case-insensitively', function (): void {
    expect(NotFoundNoise::shouldIgnore('/WP-LOGIN.PHP', ['wp-login']))->toBeTrue();
});

it('does not flag a normal path against the default ignore list', function (): void {
    expect(NotFoundNoise::shouldIgnore('about-us', NotFoundNoise::DEFAULT_IGNORE))->toBeFalse();
});

it('flags a path longer than the max length regardless of pattern match', function (): void {
    $longPath = str_repeat('a', NotFoundNoise::MAX_LENGTH + 1);

    expect(NotFoundNoise::shouldIgnore($longPath, []))->toBeTrue();
});

it('allows a path exactly at the max length', function (): void {
    $path = str_repeat('a', NotFoundNoise::MAX_LENGTH);

    expect(NotFoundNoise::shouldIgnore($path, []))->toBeFalse();
});
