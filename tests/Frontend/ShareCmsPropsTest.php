<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

it('shares the default locale and per-language is_default flags', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.defaultLocale', 'en')
        ->assertJsonPath('props.cms.languages.0.locale', 'en')
        ->assertJsonPath('props.cms.languages.0.is_default', true)
        ->assertJsonPath('props.cms.languages.1.locale', 'uk')
        ->assertJsonPath('props.cms.languages.1.is_default', false);
});
