<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Database\Seeders\LanguageSeeder;
use Tbtop\Cms\Database\Seeders\PageSeeder;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\Support\PageDuplicator;
use Tbtop\Cms\Page\Support\PageSlugWriter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new LanguageSeeder)->run();
});

// Contract 7 — mutation this catches: removing the error-class guard in
// PageSlugWriter::slugBase()/sync() would give an error page the same
// empty-path treatment as home, colliding with it at '/'.
it('never writes a PageSlug row for an error page, home still resolves at the empty path', function (): void {
    (new PageSeeder)->run();

    $home = Page::query()->where('is_system', true)->whereNull('error_class')->firstOrFail();
    $notFound = Page::query()->where('error_class', ErrorClass::ClientError)->firstOrFail();

    expect($home->pathFor('en'))->toBe('')
        ->and($notFound->pathFor('en'))->toBeNull()
        ->and(PageSlug::query()->where('page_id', $notFound->id)->exists())->toBeFalse();
});

it('does not add a PageSlug row for an error page on an explicit sync() call either', function (): void {
    $page = Page::factory()->create(['is_system' => true, 'error_class' => ErrorClass::ServerError]);

    app(PageSlugWriter::class)->sync($page, ['en' => 'should-be-ignored']);

    expect(PageSlug::query()->where('page_id', $page->id)->exists())->toBeFalse();
});

// Contract 8 — mutation this catches: the seeder locating error pages by the
// home lookup (is_system + type) instead of error_class would either create
// a duplicate error page on the second run, or find the home row and skip
// creating an error page at all.
it('is idempotent: running the seeder twice yields exactly one home, one 4xx and one 5xx page', function (): void {
    (new PageSeeder)->run();
    (new PageSeeder)->run();

    expect(Page::query()->where('is_system', true)->whereNull('error_class')->count())->toBe(1)
        ->and(Page::query()->where('error_class', ErrorClass::ClientError)->count())->toBe(1)
        ->and(Page::query()->where('error_class', ErrorClass::ServerError)->count())->toBe(1)
        ->and(Page::query()->where('is_system', true)->count())->toBe(3);
});

// error_class is immutable once set.
it('forbids changing an error page\'s class after creation', function (): void {
    $page = Page::factory()->create(['is_system' => true, 'error_class' => ErrorClass::ClientError]);

    expect(fn () => $page->update(['error_class' => ErrorClass::ServerError]))->toThrow(DomainException::class);
});

// Contract 9 — mutation this catches: PageDuplicator not clearing
// error_class would try to save a second row with the same class, tripping
// the column's unique constraint.
it('clears error_class and is_system when duplicating an error page', function (): void {
    $source = Page::factory()->create(['is_system' => true, 'error_class' => ErrorClass::ClientError, 'name' => ['en' => '404']]);

    $copy = (new PageDuplicator)->duplicate($source);

    expect($copy->is_system)->toBeFalse()
        ->and($copy->error_class)->toBeNull();

    // The unique constraint on error_class would reject a second ClientError
    // row outright — proves the copy really persisted with a null class.
    expect(Page::query()->where('error_class', ErrorClass::ClientError)->count())->toBe(1);
});
