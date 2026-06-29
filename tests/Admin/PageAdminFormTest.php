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
