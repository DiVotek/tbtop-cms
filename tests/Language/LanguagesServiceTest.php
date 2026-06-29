<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Database\Seeders\LanguageSeeder;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Language\Models\Language;

uses(RefreshDatabase::class);

it('reads the default language', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);

    $languages = app(Languages::class);

    expect($languages->defaultLocale())->toBe('en')
        ->and($languages->default())->not->toBeNull();
});

it('changes the default language atomically', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);

    app(Languages::class)->setDefault('uk');

    expect(app(Languages::class)->defaultLocale())->toBe('uk')
        ->and(Language::where('is_default', true)->pluck('locale')->all())->toBe(['uk']);
});

it('throws when setting a non-existent default', function (): void {
    expect(fn () => app(Languages::class)->setDefault('zz'))->toThrow(InvalidArgumentException::class);
});

it('exposes independent admin and frontend active lists', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true, 'is_frontend_active' => false]);

    $languages = app(Languages::class);

    expect(collect($languages->adminActive())->pluck('locale')->all())->toEqualCanonicalizing(['en', 'uk'])
        ->and(collect($languages->frontendActive())->pluck('locale')->all())->toEqualCanonicalizing(['en']);
});

it('sets the current locale only when frontend-active', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true, 'is_frontend_active' => true]);

    app(Languages::class)->setCurrent('uk');

    expect(app(Languages::class)->current()['locale'])->toBe('uk')
        ->and(app()->getLocale())->toBe('uk');
});

it('rejects setting a non-frontend-active current locale', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true, 'is_frontend_active' => false]);

    expect(fn () => app(Languages::class)->setCurrent('uk'))->toThrow(InvalidArgumentException::class);
});

it('syncs the application fallback locale to the default language', function (): void {
    Language::factory()->default()->create(['locale' => 'uk']);

    app(Languages::class)->refresh();

    expect(app()->getFallbackLocale())->toBe('uk');
});

it('seeds the configured default language', function (): void {
    config()->set('tbtop-cms.languages.seed', [
        ['name' => 'English', 'locale' => 'en', 'is_default' => true, 'is_admin_active' => true, 'is_frontend_active' => true],
    ]);

    $this->seed(LanguageSeeder::class);

    expect(Language::where('locale', 'en')->where('is_default', true)->exists())->toBeTrue();
});
