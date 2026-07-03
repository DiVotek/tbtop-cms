<?php

declare(strict_types=1);

use Tbtop\Cms\Block\Support\LocaleFlattener;

$locales = ['en', 'uk', 'de'];

it('flattens a locale map to the active locale', function () use ($locales): void {
    $out = LocaleFlattener::flattenData(['title' => ['en' => 'Hi', 'uk' => 'Pryvit']], 'en', 'uk', $locales);

    expect($out)->toBe(['title' => 'Hi']);
});

it('falls back to the default locale when the active value is missing', function () use ($locales): void {
    $out = LocaleFlattener::flattenData(['title' => ['uk' => 'Pryvit']], 'en', 'uk', $locales);

    expect($out)->toBe(['title' => 'Pryvit']);
});

it('yields null when neither active nor fallback is present', function () use ($locales): void {
    $out = LocaleFlattener::flattenData(['title' => ['de' => 'Hallo']], 'en', 'uk', $locales);

    expect($out)->toBe(['title' => null]);
});

it('passes non-translatable scalars through untouched', function () use ($locales): void {
    $out = LocaleFlattener::flattenData(['count' => 3, 'enabled' => true], 'en', 'uk', $locales);

    expect($out)->toBe(['count' => 3, 'enabled' => true]);
});

it('recurses into nested objects', function () use ($locales): void {
    $out = LocaleFlattener::flattenData(
        ['cta' => ['label' => ['en' => 'Go', 'uk' => 'Idy'], 'url' => '/x']],
        'en',
        'uk',
        $locales,
    );

    expect($out)->toBe(['cta' => ['label' => 'Go', 'url' => '/x']]);
});

it('flattens each repeater row without treating the row list as a map', function () use ($locales): void {
    $out = LocaleFlattener::flattenData(
        ['items' => [
            ['title' => ['en' => 'A', 'uk' => 'Ah']],
            ['title' => ['en' => 'B', 'uk' => 'Be']],
        ]],
        'en',
        'uk',
        $locales,
    );

    expect($out)->toBe(['items' => [['title' => 'A'], ['title' => 'B']]]);
});

it('still resolves the active locale when a sibling key is no longer a known locale, for a field the schema marks translatable', function (): void {
    $out = LocaleFlattener::flattenData(
        ['title' => ['en' => 'Hello', 'fr' => 'Bonjour']],
        'en',
        'uk',
        ['en', 'uk'],
        translatableFields: ['title'],
    );

    expect($out)->toBe(['title' => 'Hello']);
});

it('still resolves the fallback locale when the active locale is orphaned, for a field the schema marks translatable', function (): void {
    $out = LocaleFlattener::flattenData(
        ['title' => ['uk' => 'Pryvit', 'fr' => 'Bonjour']],
        'en',
        'uk',
        ['en', 'uk'],
        translatableFields: ['title'],
    );

    expect($out)->toBe(['title' => 'Pryvit']);
});

it('does not tolerate an orphaned locale key without a schema confirming the field is translatable', function (): void {
    $out = LocaleFlattener::flattenData(['title' => ['en' => 'Hello', 'fr' => 'Bonjour']], 'en', 'uk', ['en', 'uk']);

    expect($out)->toBe(['title' => ['en' => 'Hello', 'fr' => 'Bonjour']]);
});

it('does not treat a field coincidentally named after the active locale as a locale map when the schema says it is not translatable', function () use ($locales): void {
    $out = LocaleFlattener::flattenData(
        ['settings' => ['en' => 'some value', 'mode' => 'dark']],
        'en',
        'uk',
        $locales,
        translatableFields: [],
    );

    expect($out)->toBe(['settings' => ['en' => 'some value', 'mode' => 'dark']]);
});

it('only flattens fields the schema declares translatable', function () use ($locales): void {
    $out = LocaleFlattener::flattenData(
        [
            'title' => ['en' => 'Hi', 'uk' => 'Pryvit'],
            'settings' => ['en' => 'some value', 'url' => '/about'],
        ],
        'en',
        'uk',
        $locales,
        translatableFields: ['title'],
    );

    expect($out)->toBe([
        'title' => 'Hi',
        'settings' => ['en' => 'some value', 'url' => '/about'],
    ]);
});
