<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('creates a page and writes a localized slug', function (): void {
    $page = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'About Us'],
        'parent_id' => '',
        'is_indexable' => true,
        'sort_order' => 0,
    ]);

    expect($page->exists)->toBeTrue()
        ->and($page->status)->toBe(PageStatus::Published)
        ->and($page->pathFor('en'))->toBe('about-us');
});

it('updates an existing page through the admin save path', function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'Old'], 'title' => ['en' => 'Old']]);

    PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'New Name'],
        'title' => ['en' => 'Old'],
        'parent_id' => '',
    ], $page->id);

    $page->refresh();

    expect($page->getTranslation('name', 'en'))->toBe('New Name')
        ->and($page->status)->toBe(PageStatus::Published);
});

it('rejects placing a page under its own descendant', function (): void {
    $parent = Page::factory()->category()->create(['name' => ['en' => 'Parent']]);
    $child = Page::factory()->category()->create(['name' => ['en' => 'Child'], 'parent_id' => $parent->id]);

    PageForm::save([
        'type' => 'category',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'Parent'],
        'parent_id' => (string) $child->id,
    ], $parent->id);
})->throws(ValidationException::class);

it('loads translations and localized paths for editing', function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'Contact']]);

    $row = PageForm::loadRow($page->id);

    expect($row['name'])->toBe(['en' => 'Contact'])
        ->and($row['paths'])->toBe(['en' => 'contact']);
});

it('locks system page structure but allows content edits', function (): void {
    $page = Page::factory()->create([
        'name' => ['en' => 'Home'],
        'title' => ['en' => 'Home'],
        'is_system' => true,
        'status' => PageStatus::Published,
    ]);

    PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'Home Updated'],
        'title' => ['en' => 'Home'],
        'parent_id' => '',
    ], $page->id);

    $page->refresh();

    expect($page->status)->toBe(PageStatus::Published)
        ->and($page->getTranslation('name', 'en'))->toBe('Home Updated');
});

it('rejects a page type that is not registered', function (): void {
    PageForm::save([
        'type' => 'bogus-type',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'Bad Type'],
        'parent_id' => '',
    ]);
})->throws(ValidationException::class);

it('rejects a status value outside the PageStatus enum', function (): void {
    PageForm::save([
        'type' => 'page',
        'status' => 'archived',
        'name' => ['en' => 'Bad Status'],
        'parent_id' => '',
    ]);
})->throws(ValidationException::class);

it('saves and reloads the page image media id', function (): void {
    $page = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'With Image'],
        'parent_id' => '',
        'image' => '42',
    ]);

    expect($page->image)->toBe('42')
        ->and(PageForm::loadRow($page->id)['image'])->toBe('42');
});

it('stores a null image when the form field is empty', function (): void {
    $page = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Draft->value,
        'name' => ['en' => 'Without Image'],
        'parent_id' => '',
        'image' => '',
    ]);

    expect($page->image)->toBeNull()
        ->and(PageForm::loadRow($page->id)['image'])->toBe('');
});

it('updates the localized slug when the edit form submits a changed slug map', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Old Name']]);
    expect($page->pathFor('en'))->toBe('old-name');

    PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'Old Name'],
        'parent_id' => '',
        'slug' => ['en' => 'brand-new-slug'],
    ], $page->id);

    expect($page->fresh()->pathFor('en'))->toBe('brand-new-slug');
});

it('loads the localized slug map for editing', function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'Contact']]);

    $row = PageForm::loadRow($page->id);

    expect($row['slug'])->toBe(['en' => 'contact']);
});

it('persists published_at and unpublish_at when both are set', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'Scheduled Page']]);
    $publishedAt = now()->addDay()->startOfSecond();
    $unpublishAt = now()->addWeek()->startOfSecond();

    PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'Scheduled Page'],
        'parent_id' => '',
        'published_at' => $publishedAt->toIso8601String(),
        'unpublish_at' => $unpublishAt->toIso8601String(),
    ], $page->id);

    $page->refresh();

    expect($page->published_at)->not->toBeNull()
        ->and($page->published_at->equalTo($publishedAt))->toBeTrue()
        ->and($page->unpublish_at)->not->toBeNull()
        ->and($page->unpublish_at->equalTo($unpublishAt))->toBeTrue();

    $row = PageForm::loadRow($page->id);
    expect($row['published_at'])->not->toBeNull()
        ->and($row['unpublish_at'])->not->toBeNull();
});

it('clears published_at and unpublish_at to null when the form submits them empty', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Scheduled Page'],
        'published_at' => now()->addDay(),
        'unpublish_at' => now()->addWeek(),
    ]);

    PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'Scheduled Page'],
        'parent_id' => '',
        'published_at' => '',
        'unpublish_at' => '',
    ], $page->id);

    $page->refresh();

    expect($page->published_at)->toBeNull()
        ->and($page->unpublish_at)->toBeNull();
});

it('rejects an unpublish_at that is not strictly after published_at', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'Scheduled Page']]);
    $at = now()->addDay();

    try {
        PageForm::save([
            'type' => 'page',
            'status' => PageStatus::Published->value,
            'name' => ['en' => 'Scheduled Page'],
            'parent_id' => '',
            'published_at' => $at->toIso8601String(),
            'unpublish_at' => $at->toIso8601String(),
        ], $page->id);

        expect(false)->toBeTrue('Expected a ValidationException to be thrown.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('unpublish_at');
    }
});

it('rejects an unpublish_at earlier than published_at', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'Scheduled Page']]);

    PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'Scheduled Page'],
        'parent_id' => '',
        'published_at' => now()->addWeek()->toIso8601String(),
        'unpublish_at' => now()->addDay()->toIso8601String(),
    ], $page->id);
})->throws(ValidationException::class);

it('creates a page unaffected by scheduling — the create form never submits schedule fields', function (): void {
    $page = PageForm::save([
        'type' => 'page',
        'status' => PageStatus::Published->value,
        'name' => ['en' => 'Fresh Page'],
        'parent_id' => '',
    ]);

    expect($page->published_at)->toBeNull()
        ->and($page->unpublish_at)->toBeNull();
});
