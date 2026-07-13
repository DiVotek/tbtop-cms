<?php

declare(strict_types=1);

use Tbtop\Cms\Language\LocaleCatalog;

it('resolves the native name for a known locale', function (): void {
    expect(LocaleCatalog::name('uk'))->toBe('Українська')
        ->and(LocaleCatalog::name('en'))->toBe('English');
});

it('returns null for an unknown locale', function (): void {
    expect(LocaleCatalog::name('zz'))->toBeNull();
});

it('builds options as value/label pairs with the native name and code in the label', function (): void {
    $options = LocaleCatalog::options();
    $uk = collect($options)->firstWhere('value', 'uk');

    expect($uk)->not->toBeNull()
        ->and($uk['label'])->toBe('Українська (uk)');
});

it('keeps options in sync with all()', function (): void {
    expect(LocaleCatalog::options())->toHaveCount(count(LocaleCatalog::all()));
});
