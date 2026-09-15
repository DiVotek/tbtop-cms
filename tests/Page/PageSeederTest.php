<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
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

    $home = Page::where('is_system', true)->whereNull('error_class')->get();

    expect($home)->toHaveCount(1)
        ->and($home->first()->type)->toBe('page')
        ->and($home->first()->status)->toBe(PageStatus::Published)
        ->and($home->first()->is_indexable)->toBeTrue();
});

it('seeds the home name under the default locale', function (): void {
    (new LanguageSeeder)->run();
    (new PageSeeder)->run();

    $home = Page::where('is_system', true)->whereNull('error_class')->first();

    expect($home->getTranslation('name', 'en'))->toBe('Home')
        ->and($home->getTranslation('title', 'en'))->toBe('Home');
});

// A host's DatabaseSeeder runs with WithoutModelEvents, so PageObserver never
// fires and the seeder itself has to write the home page's slug.
it('seeds the home page slug when model events are muted', function (): void {
    (new LanguageSeeder)->run();

    Model::withoutEvents(function (): void {
        (new PageSeeder)->run();
    });

    $slug = Page::where('is_system', true)->first()->slugs()->where('locale', 'en')->first();

    expect($slug)->not->toBeNull()
        ->and($slug->slug)->toBe('')
        ->and($slug->path)->toBe('');
});

it('seeds the home name under a fallback locale when none is configured', function (): void {
    config()->set('app.fallback_locale', 'en');

    (new PageSeeder)->run();

    $translations = Page::where('is_system', true)->whereNull('error_class')->first()->getTranslations('name');

    expect($translations)->toHaveKey('en')
        ->and($translations)->not->toHaveKey('');
});

it('seeds exactly one 4xx and one 5xx error page, idempotently', function (): void {
    (new LanguageSeeder)->run();
    (new PageSeeder)->run();
    (new PageSeeder)->run();

    $errorPages = Page::query()->whereNotNull('error_class')->get();

    expect($errorPages)->toHaveCount(2)
        ->and($errorPages->pluck('error_class')->map->value->sort()->values()->all())->toBe(['4xx', '5xx'])
        ->and($errorPages->every(fn (Page $page): bool => $page->is_system && ! $page->is_indexable))->toBeTrue();
});
