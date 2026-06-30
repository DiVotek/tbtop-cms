<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Admin\Pages\Support\RedirectForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\Models\Redirect;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('creates a manual global redirect', function (): void {
    $redirect = RedirectForm::save(['from_path' => 'old', 'to_path' => 'new', 'locale' => '', 'status_code' => '301']);

    expect($redirect->from_path)->toBe('old')
        ->and($redirect->to_path)->toBe('new')
        ->and($redirect->locale)->toBeNull()
        ->and($redirect->status_code)->toBe(301)
        ->and($redirect->is_auto)->toBeFalse();
});

it('rejects a self redirect', function (): void {
    RedirectForm::save(['from_path' => 'loop', 'to_path' => 'loop', 'locale' => '', 'status_code' => '301']);
})->throws(ValidationException::class);

it('rejects a redirect that shadows a live page', function (): void {
    Page::factory()->create(['name' => ['en' => 'About']]);

    RedirectForm::save(['from_path' => 'about', 'to_path' => 'somewhere', 'locale' => '', 'status_code' => '301']);
})->throws(ValidationException::class);

it('rejects a redirect that closes a loop', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'b', 'to_path' => 'a', 'status_code' => 301]);

    RedirectForm::save(['from_path' => 'a', 'to_path' => 'b', 'locale' => 'en', 'status_code' => '301']);
})->throws(ValidationException::class);

it('rejects an unsupported status code', function (): void {
    RedirectForm::save(['from_path' => 'x', 'to_path' => 'y', 'locale' => '', 'status_code' => '307']);
})->throws(ValidationException::class);

it('updates a redirect and marks it manual', function (): void {
    $auto = Redirect::create(['locale' => 'en', 'from_path' => 'p', 'to_path' => 'q', 'status_code' => 301, 'is_auto' => true]);

    RedirectForm::save(['from_path' => 'p', 'to_path' => 'q2', 'locale' => 'en', 'status_code' => '302'], $auto->id);
    $auto->refresh();

    expect($auto->to_path)->toBe('q2')
        ->and($auto->status_code)->toBe(302)
        ->and($auto->is_auto)->toBeFalse();
});

it('loads a redirect row for editing', function (): void {
    $redirect = Redirect::create(['locale' => 'en', 'from_path' => 'm', 'to_path' => 'n', 'status_code' => 302]);

    $row = RedirectForm::loadRow($redirect->id);

    expect($row['from_path'])->toBe('m')
        ->and($row['to_path'])->toBe('n')
        ->and($row['locale'])->toBe('en')
        ->and($row['status_code'])->toBe('302');
});
