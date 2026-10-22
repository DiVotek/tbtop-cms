<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Refs\PageTypeFilter;
use Tbtop\Cms\Tests\Fixtures\LegalServicePageType;
use Tbtop\Cms\Tests\Fixtures\SpecialistPageType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PageTypeRegistry::extend(fn (): array => [SpecialistPageType::class, LegalServicePageType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

// The subtype warning (accepts()) and the pages field's search (apply()) must agree on every page.
it('accepts exactly the pages its query selects', function (array $pairs): void {
    $category = Page::create(['type' => 'category', 'status' => PageStatus::Published, 'name' => ['en' => 'Team']]);
    foreach ([null, 'specialist', 'legal_service'] as $subtype) {
        Page::create(['type' => 'article', 'subtype' => $subtype, 'parent_id' => $category->id, 'status' => PageStatus::Published, 'name' => ['en' => "Article {$subtype}"]]);
    }
    Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Plain']]);

    $filter = PageTypeFilter::of($pairs);
    $selected = $filter->apply(Page::query())->pluck('id')->sort()->values()->all();
    $accepted = Page::query()->get()
        ->filter(static fn (Page $page): bool => $filter->accepts((string) $page->type, $page->subtype === null ? null : (string) $page->subtype))
        ->pluck('id')->sort()->values()->all();

    expect($accepted)->toBe($selected);
})->with([
    'any page' => [[]],
    'a base' => [['article']],
    'a subtype' => [['article/legal_service']],
    'a subtype and a base' => [['article/specialist', 'page']],
    'a type with no pages' => [['category/legal_service']],
]);
