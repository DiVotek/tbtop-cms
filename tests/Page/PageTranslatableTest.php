<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

it('stores scalar attributes per locale', function (): void {
    $page = Page::factory()->create([
        'type' => 'page',
        'name' => ['en' => 'About', 'uk' => 'Про нас'],
    ])->fresh();

    expect($page->getTranslation('name', 'uk'))->toBe('Про нас')
        ->and($page->getTranslation('name', 'en'))->toBe('About');
});

it('stores block content per locale', function (): void {
    $page = Page::factory()->create([
        'type' => 'page',
        'content' => ['en' => [['kind' => 'Hero', 'data' => []]]],
    ])->fresh();

    expect($page->getTranslation('content', 'en'))->toBe([['kind' => 'Hero', 'data' => []]]);
});

it('falls back to the default locale for a missing translation', function (): void {
    config()->set('translatable.fallback_locale', 'en');

    $page = Page::factory()->create([
        'type' => 'page',
        'name' => ['en' => 'About'],
    ])->fresh();

    expect($page->getTranslation('name', 'uk'))->toBe('About');
});
