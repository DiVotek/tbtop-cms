<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Language\Observers\LanguageObserver;

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

it('forbids unsetting the only default language', function (): void {
    $language = Language::factory()->default()->create(['locale' => 'en']);

    expect(fn () => $language->update(['is_default' => false]))->toThrow(DomainException::class);

    expect(Language::where('is_default', true)->count())->toBe(1);
});

it('converges to exactly one default when two concurrent saves race', function (): void {
    $a = Language::factory()->default()->create(['locale' => 'en']);
    $b = Language::factory()->create(['locale' => 'uk']);
    $c = Language::factory()->create(['locale' => 'de']);

    $observer = app(LanguageObserver::class);

    Language::withoutEvents(function () use ($b, $c, $observer): void {
        Language::whereKey($b->id)->update(['is_default' => true]);
        Language::whereKey($c->id)->update(['is_default' => true]);

        $b->is_default = true;
        $observer->saved($b);

        $c->is_default = true;
        $observer->saved($c);
    });

    expect(Language::where('is_default', true)->count())->toBe(1)
        ->and(Language::where('is_default', true)->value('locale'))->toBe('de');
});

it('translates the invariant messages shown in the admin', function (string $locale, string $key, Closure $act): void {
    app()->setLocale($locale);
    $language = Language::factory()->default()->create(['locale' => 'en']);
    $other = Language::factory()->create([
        'locale' => 'de',
        'is_default' => false,
        'is_admin_active' => false,
        'is_frontend_active' => false,
    ]);

    [$subject, $run] = $act($language, $other);

    expect($run)->toThrow(DomainException::class, __("tbtop-cms::languages.errors.{$key}", ['locale' => $subject->locale]));
})->with(['en', 'uk'])->with([
    'delete default' => ['default_not_deletable', fn (Language $default, Language $other): array => [$default, fn () => $default->delete()]],
    'unset only default' => ['only_default', fn (Language $default, Language $other): array => [$default, fn () => $default->update(['is_default' => false])]],
    'frontend without admin' => ['frontend_requires_admin', fn (Language $default, Language $other): array => [$other, fn () => $other->update(['is_frontend_active' => true])]],
    'new frontend without admin' => ['frontend_requires_admin', fn (Language $default, Language $other): array => [
        new Language(['locale' => 'fr']),
        fn () => Language::factory()->create(['locale' => 'fr', 'is_admin_active' => false, 'is_frontend_active' => true]),
    ]],
]);

it('keeps the english invariant messages unchanged', function (): void {
    app()->setLocale('en');

    expect(__('tbtop-cms::languages.errors.default_not_deletable', ['locale' => 'en']))
        ->toBe("Default language 'en' cannot be deleted. Reassign default first.")
        ->and(__('tbtop-cms::languages.errors.frontend_requires_admin', ['locale' => 'de']))
        ->toBe("Language 'de' cannot be frontend-active without being admin-active.")
        ->and(__('tbtop-cms::languages.errors.only_default', ['locale' => 'en']))
        ->toBe("Language 'en' is the only default and cannot be unset. Assign another default first.");
});
