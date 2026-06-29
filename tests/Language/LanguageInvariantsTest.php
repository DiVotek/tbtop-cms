<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Language\Models\Language;

uses(RefreshDatabase::class);

it('forces a default language to be admin and frontend active', function (): void {
    $language = Language::factory()->create([
        'locale' => 'en',
        'is_default' => true,
        'is_admin_active' => false,
        'is_frontend_active' => false,
    ]);

    expect($language->is_admin_active)->toBeTrue()
        ->and($language->is_frontend_active)->toBeTrue();
});

it('keeps exactly one default language', function (): void {
    Language::factory()->create(['locale' => 'en', 'is_default' => true]);
    Language::factory()->create(['locale' => 'uk', 'is_default' => true]);

    expect(Language::where('is_default', true)->pluck('locale')->all())->toBe(['uk']);
});

it('forbids deleting the default language', function (): void {
    $language = Language::factory()->default()->create(['locale' => 'en']);

    expect(fn () => $language->delete())->toThrow(DomainException::class);
});

it('allows soft-deleting a non-default language', function (): void {
    $language = Language::factory()->create(['locale' => 'uk', 'is_default' => false]);

    $language->delete();

    expect(Language::withTrashed()->find($language->id)->trashed())->toBeTrue();
});

it('rejects a new frontend-active language that is not admin-active', function (): void {
    expect(fn () => Language::factory()->create([
        'locale' => 'uk',
        'is_default' => false,
        'is_admin_active' => false,
        'is_frontend_active' => true,
    ]))->toThrow(DomainException::class);
});

it('cascades admin-inactive to frontend-inactive', function (): void {
    $language = Language::factory()->create([
        'locale' => 'uk',
        'is_default' => false,
        'is_admin_active' => true,
        'is_frontend_active' => true,
    ]);

    $language->update(['is_admin_active' => false]);

    expect($language->is_frontend_active)->toBeFalse();
});

it('rejects turning frontend active on while admin stays off', function (): void {
    $language = Language::factory()->create([
        'locale' => 'uk',
        'is_default' => false,
        'is_admin_active' => false,
        'is_frontend_active' => false,
    ]);

    expect(fn () => $language->update(['is_frontend_active' => true]))->toThrow(DomainException::class);
});
