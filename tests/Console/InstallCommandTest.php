<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

afterEach(function (): void {
    $published = config_path('tbtop-cms.php');

    if (is_file($published)) {
        unlink($published);
    }
});

it('prepares default languages and a single home page on a fresh app', function (): void {
    $this->artisan('cms:install')->assertExitCode(0);

    expect(Language::count())->toBe(1)
        ->and(Page::where('is_system', true)->count())->toBe(1);
});

it('is safe to run twice without duplicating data', function (): void {
    $this->artisan('cms:install')->assertExitCode(0);
    $this->artisan('cms:install')->assertExitCode(0);

    expect(Language::count())->toBe(1)
        ->and(Page::where('is_system', true)->count())->toBe(1);
});
