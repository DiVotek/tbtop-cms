<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\ContentLocales;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Language\Models\Language;

uses(RefreshDatabase::class);

it('wires content locales from admin-active languages', function (): void {
    Language::factory()->default()->create(['locale' => 'en', 'sort_order' => 0]);
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true, 'sort_order' => 1]);
    Language::factory()->create(['locale' => 'de', 'is_admin_active' => false, 'is_frontend_active' => false, 'sort_order' => 2]);

    $languages = app(Languages::class);
    $languages->refresh();

    ContentLocales::sync($languages);

    expect(config('tbtop-admin.content_locales'))->toBe(['en', 'uk'])
        ->and(config('tbtop-admin.default_content_locale'))->toBe('en');
});

it('keeps the configured default when no language is admin-active', function (): void {
    config(['tbtop-admin.content_locales' => ['en']]);
    Language::factory()->create(['locale' => 'fr', 'is_admin_active' => false, 'is_frontend_active' => false]);

    $languages = app(Languages::class);
    $languages->refresh();

    ContentLocales::sync($languages);

    expect(config('tbtop-admin.content_locales'))->toBe(['en']);
});

it('re-syncs content locales when a language is saved', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk', 'is_admin_active' => true]);

    expect(config('tbtop-admin.content_locales'))->toBe(['en', 'uk']);
});
