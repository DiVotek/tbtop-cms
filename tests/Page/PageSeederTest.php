<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Database\Seeders\LanguageSeeder;
use Tbtop\Cms\Database\Seeders\PageSeeder;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

it('seeds a single published system home page', function (): void {
    (new LanguageSeeder)->run();
    (new PageSeeder)->run();
    (new PageSeeder)->run();

    $home = Page::where('is_system', true)->get();

    expect($home)->toHaveCount(1)
        ->and($home->first()->type)->toBe('page')
        ->and($home->first()->status)->toBe(PageStatus::Published)
        ->and($home->first()->is_indexable)->toBeTrue();
});

it('seeds the home name under the default locale', function (): void {
    (new LanguageSeeder)->run();
    (new PageSeeder)->run();

    $home = Page::where('is_system', true)->first();

    expect($home->getTranslation('name', 'en'))->toBe('Home');
});
