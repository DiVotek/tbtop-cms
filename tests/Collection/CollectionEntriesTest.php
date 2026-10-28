<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Pages\Support\EntryForm;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Facades\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\ReviewsCollection;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
    config(['tbtop-cms.collections' => [ReviewsCollection::class]]);
    app()->instance(CollectionRegistry::class, new CollectionRegistry);
});

/** @param  array<string, mixed>  $data */
function entriesReview(array $data, string $status = Entry::PUBLISHED, int $sort = 0): Entry
{
    return Entry::query()->create(['collection' => 'review', 'data' => $data, 'status' => $status, 'sort_order' => $sort]);
}

/** What a new request sees: fresh scoped services, the facade included. */
function freshRequest(): void
{
    app()->forgetScopedInstances();
    Cms::clearResolvedInstances();
}

it('lists the published entries in the editor order, each resolved by its fields', function (): void {
    $service = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Audit']]);
    $second = entriesReview(['author' => 'Bo', 'text' => ['en' => 'Fine'], 'rating' => '4'], sort: 2);
    $first = entriesReview(['author' => 'Al', 'text' => ['en' => 'Great'], 'rating' => 5, 'services' => [(string) $service->id]], sort: 1);
    entriesReview(['author' => 'Draft'], Entry::DRAFT);

    $entries = Cms::collection('review')->all();

    expect(array_column($entries, 'author'))->toBe(['Al', 'Bo'])
        ->and($entries[0]['id'])->toBe($first->id)
        ->and($entries[0]['text'])->toBe('Great')
        ->and($entries[1]['rating'])->toBe(4)
        ->and($entries[0]['services'][0]['name'])->toBe('Audit')
        ->and($entries[1]['services'])->toBe([])
        ->and($second->id)->toBeInt();
});

it('picks the locale of a translatable field and falls back to the default one', function (): void {
    entriesReview(['author' => 'Al', 'text' => ['en' => 'Great', 'uk' => 'Чудово']], sort: 1);
    entriesReview(['author' => 'Bo', 'text' => ['en' => 'Fine']], sort: 2);

    expect(array_column(Cms::collection('review', 'uk')->all(), 'text'))->toBe(['Чудово', 'Fine']);
});

it('filters in memory: latest, related to a page, picked ids, a field value', function (): void {
    $audit = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Audit']]);
    $tax = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Tax']]);
    $a = entriesReview(['author' => 'A', 'rating' => 5, 'services' => [(string) $audit->id]], sort: 3);
    $b = entriesReview(['author' => 'B', 'rating' => 3, 'services' => [(string) $audit->id, (string) $tax->id]], sort: 1);
    $c = entriesReview(['author' => 'C', 'rating' => 5], sort: 2);

    $list = Cms::collection('review');

    expect(array_column($list->latest(2)->all(), 'author'))->toBe(['C', 'B'])
        ->and(array_column($list->relatedTo($audit)->all(), 'author'))->toBe(['B', 'A'])
        ->and(array_column($list->relatedTo($tax->id)->all(), 'author'))->toBe(['B'])
        ->and($list->relatedTo(null)->all())->toBe([])
        ->and(array_column($list->only([$c->id, 999, $a->id])->all(), 'author'))->toBe(['C', 'A'])
        ->and(array_column($list->where('rating', 5)->all(), 'author'))->toBe(['C', 'A'])
        ->and($list->take(1)->first()['author'] ?? null)->toBe('B')
        ->and(count($list))->toBe(3)
        ->and($b->id)->toBeInt();
});

it('serves a cached list until an entry is saved through the admin', function (): void {
    entriesReview(['author' => 'Al'], sort: 1);
    expect(Cms::collection('review')->count())->toBe(1);

    // Written behind the CMS's back: the cached list stays as it was.
    Entry::query()->where('collection', 'review')->toBase()->update(['data' => json_encode(['author' => 'Changed'])]);
    freshRequest();
    expect(Cms::collection('review')->first()['author'] ?? null)->toBe('Al');

    EntryForm::create(app(CollectionRegistry::class)->get('review'), ['author' => 'Bo', 'status' => Entry::PUBLISHED]);
    freshRequest();
    expect(array_column(Cms::collection('review')->all(), 'author'))->toBe(['Changed', 'Bo']);
});

it('follows a reorder written through the entries query', function (): void {
    $a = entriesReview(['author' => 'A'], sort: 0);
    $b = entriesReview(['author' => 'B'], sort: 1);
    expect(array_column(Cms::collection('review')->all(), 'author'))->toBe(['A', 'B']);

    // What the admin table's drag reorder does: a query update, no model events.
    Entry::query()->whereKey($a->id)->update(['sort_order' => 5]);
    freshRequest();

    expect(array_column(Cms::collection('review')->all(), 'author'))->toBe(['B', 'A'])
        ->and($b->id)->toBeInt();
});

it('keeps the default-locale title in a column for the admin list', function (): void {
    $entry = entriesReview(['author' => 'Al']);
    expect($entry->title)->toBe('Al');

    $entry->update(['data' => ['author' => 'Alex']]);
    expect($entry->refresh()->title)->toBe('Alex');
});

it('throws for a collection that is not registered', function (): void {
    Cms::collection('ghost');
})->throws(InvalidArgumentException::class, 'Collection [ghost] is not registered.');
