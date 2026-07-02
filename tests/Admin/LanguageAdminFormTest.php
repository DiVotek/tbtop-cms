<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Admin\Pages\Support\LanguageForm;
use Tbtop\Cms\Language\Models\Language;

uses(RefreshDatabase::class);

it('creates a language with the given locale', function (): void {
    $language = LanguageForm::save([
        'name' => 'English',
        'locale' => 'en',
        'is_default' => true,
        'is_admin_active' => true,
        'is_frontend_active' => true,
        'sort_order' => 0,
    ]);

    expect($language->exists)->toBeTrue()
        ->and($language->locale)->toBe('en')
        ->and($language->name)->toBe('English');
});

it('keeps the locale immutable when editing an existing language', function (): void {
    $language = Language::factory()->default()->create(['locale' => 'en']);

    LanguageForm::save([
        'name' => 'English (UK)',
        'locale' => 'gb',
        'is_default' => true,
        'is_admin_active' => true,
        'is_frontend_active' => true,
    ], $language->id);

    $language->refresh();

    expect($language->locale)->toBe('en')
        ->and($language->name)->toBe('English (UK)');
});

it('accepts a regional locale up to the column width', function (): void {
    $language = LanguageForm::save([
        'name' => 'Portuguese (Brazil)',
        'locale' => 'pt-BR',
        'is_default' => true,
        'is_admin_active' => true,
        'is_frontend_active' => true,
        'sort_order' => 0,
    ]);

    expect($language->exists)->toBeTrue()
        ->and($language->locale)->toBe('pt-BR');
});

it('rejects creating a language with a locale that already exists', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);

    LanguageForm::save([
        'name' => 'English (duplicate)',
        'locale' => 'en',
        'is_default' => false,
        'is_admin_active' => true,
        'is_frontend_active' => false,
        'sort_order' => 1,
    ]);
})->throws(ValidationException::class);

it('rejects creating a language with a blank locale', function (): void {
    LanguageForm::save([
        'name' => 'No locale',
        'locale' => '',
        'is_default' => false,
        'is_admin_active' => true,
        'is_frontend_active' => false,
    ]);
})->throws(ValidationException::class);
