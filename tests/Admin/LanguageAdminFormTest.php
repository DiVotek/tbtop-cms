<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Admin\Pages\Support\LanguageForm;
use Tbtop\Cms\Language\LocaleCatalog;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

it('creates a language from a catalog locale, taking the name from the catalog', function (): void {
    $language = LanguageForm::save([
        'locale' => 'en',
        'is_default' => true,
        'is_admin_active' => true,
        'is_frontend_active' => true,
        'sort_order' => 0,
    ]);

    expect($language->exists)->toBeTrue()
        ->and($language->locale)->toBe('en')
        ->and($language->name)->toBe(LocaleCatalog::name('en'));
});

it('keeps the locale immutable when editing an existing language, but allows renaming', function (): void {
    $language = Language::factory()->default()->create(['locale' => 'en', 'name' => 'English']);

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

it('rejects creating a language with a locale that already exists', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);

    LanguageForm::save([
        'locale' => 'en',
        'is_default' => false,
        'is_admin_active' => true,
        'is_frontend_active' => false,
        'sort_order' => 1,
    ]);
})->throws(ValidationException::class);

it('rejects creating a language with a locale outside the catalog', function (): void {
    LanguageForm::save([
        'locale' => 'zz',
        'is_default' => false,
        'is_admin_active' => true,
        'is_frontend_active' => false,
    ]);
})->throws(ValidationException::class);

it('rejects creating a language with a blank locale', function (): void {
    LanguageForm::save([
        'locale' => '',
        'is_default' => false,
        'is_admin_active' => true,
        'is_frontend_active' => false,
    ]);
})->throws(ValidationException::class);

it('restores a soft-deleted language when its locale is picked again', function (): void {
    $original = LanguageForm::save(['locale' => 'de', 'sort_order' => 3]);
    $original->delete();

    $restored = LanguageForm::save([
        'locale' => 'de',
        'is_admin_active' => true,
        'is_frontend_active' => true,
        'sort_order' => 7,
    ]);

    expect($restored->id)->toBe($original->id)
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->is_frontend_active)->toBeTrue()
        ->and($restored->sort_order)->toBe(7)
        ->and(Language::query()->where('locale', 'de')->count())->toBe(1);
});

it('leaves a soft-deleted language trashed when re-picking it is rejected', function (): void {
    $original = LanguageForm::save(['locale' => 'de', 'sort_order' => 3]);
    $original->delete();

    expect(fn () => LanguageForm::save([
        'locale' => 'de',
        'is_admin_active' => false,
        'is_frontend_active' => true,
    ]))->toThrow(DomainException::class);

    expect(Language::withTrashed()->findOrFail($original->id)->trashed())->toBeTrue();
});

it('keeps a page translation intact across a language delete and revive-on-recreate cycle', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    $de = LanguageForm::save(['locale' => 'de', 'is_admin_active' => true, 'is_frontend_active' => true]);

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'de' => 'Ueber uns'],
    ]);

    $de->delete();

    LanguageForm::save([
        'locale' => 'de',
        'is_admin_active' => true,
        'is_frontend_active' => true,
    ]);

    expect($page->fresh()->getTranslation('name', 'de'))->toBe('Ueber uns');
});

it('takes the og_locale territory an editor sets, and rejects one that is not language_TERRITORY', function (): void {
    $language = Language::factory()->default()->create(['locale' => 'en', 'name' => 'English']);

    LanguageForm::save(['name' => 'English', 'og_locale' => 'en_GB', 'is_default' => true], $language->id);
    expect($language->refresh()->og_locale)->toBe('en_GB');

    expect(fn () => LanguageForm::save(['name' => 'English', 'og_locale' => 'en', 'is_default' => true], $language->id))
        ->toThrow(ValidationException::class);
    expect($language->refresh()->og_locale)->toBe('en_GB');
});
