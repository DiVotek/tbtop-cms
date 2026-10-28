<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Fields\CollectionField;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\Support\FormResolver;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\ReviewsBlock;
use Tbtop\Cms\Tests\Fixtures\ReviewsCollection;
use Tbtop\Cms\Tests\Fixtures\TeamCollection;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    config([
        'tbtop-cms.collections' => [ReviewsCollection::class, TeamCollection::class],
        'tbtop-cms.blocks.types' => [ReviewsBlock::class],
    ]);
    app()->instance(CollectionRegistry::class, new CollectionRegistry);
});

/** @param  array<string, mixed>  $data */
function fieldReview(string $author, array $data = [], string $status = Entry::PUBLISHED): Entry
{
    static $sort = 0;

    return Entry::query()->create(['collection' => 'review', 'status' => $status, 'sort_order' => $sort++, 'data' => ['author' => $author, ...$data]]);
}

/**
 * The Reviews block's props on a page, as the site gets them.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function reviewsProps(array $data, ?Page $page = null): array
{
    $content = [['id' => 'b1', 'kind' => 'Reviews', 'active' => true, 'sort' => 0, 'data' => $data]];

    return app(BlockContentResolver::class)->resolve($content, $page)[0]->props;
}

it('resolves each mode to the published entries it picks', function (): void {
    $service = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Audit']]);
    $a = fieldReview('A', ['services' => [(string) $service->id]]);
    $b = fieldReview('B');
    $c = fieldReview('C', ['services' => [(string) $service->id]]);
    $draft = fieldReview('Draft', [], Entry::DRAFT);

    $authors = static fn (array $props): array => array_column($props['reviews'], 'author');

    expect($authors(reviewsProps(['reviews' => ['mode' => 'all']])))->toBe(['A', 'B', 'C'])
        ->and($authors(reviewsProps(['reviews' => ['mode' => 'latest', 'limit' => 2]])))->toBe(['C', 'B'])
        ->and($authors(reviewsProps(['reviews' => ['mode' => 'related']], $service)))->toBe(['A', 'C'])
        ->and($authors(reviewsProps(['reviews' => ['mode' => 'related']])))->toBe([])
        ->and($authors(reviewsProps(['reviews' => ['mode' => 'manual', 'ids' => [(string) $c->id, (string) $draft->id, (string) $a->id]]])))->toBe(['C', 'A'])
        // An untouched field takes the first allowed mode: all.
        ->and($authors(reviewsProps([])))->toBe(['A', 'B', 'C'])
        ->and($b->id)->toBeInt();
});

it('gives one entry or null with single()', function (): void {
    fieldReview('A');
    $b = fieldReview('B');

    expect(reviewsProps(['featured' => ['mode' => 'manual', 'ids' => [(string) $b->id]]])['featured']['author'] ?? null)->toBe('B')
        ->and(reviewsProps(['featured' => ['mode' => 'latest']])['featured']['author'] ?? null)->toBe('B')
        // "all" is not among the field's modes, so the first one (manual) applies: nothing picked.
        ->and(reviewsProps(['featured' => ['mode' => 'all']])['featured'])->toBeNull();
});

it('caps the entries at max()', function (): void {
    fieldReview('A');
    fieldReview('B');
    fieldReview('C');
    $field = (new S)->collection('reviews')->of('review')->max(2);

    $props = (new FormResolver)->resolve([$field], ['reviews' => ['mode' => 'all']], new ResolveContext('en', 'en'));

    expect(array_column($props['reviews'], 'author'))->toBe(['A', 'B']);
});

it('offers the related mode only when the collection has a pages field', function (): void {
    $reviews = (new S)->collection('reviews')->of('review')->toNode()->options;
    $team = (new S)->collection('team')->of('team')->toNode()->options;
    $picked = (new S)->collection('team')->of('team')->modes(['related', 'manual'])->toNode()->options;

    expect($reviews['allowedModes'])->toBe(['all', 'latest', 'related', 'manual'])
        ->and($reviews['searchEndpoint'])->toBe('/admin/entry-search')
        ->and($reviews['collectionLabel'])->toBe('Reviews')
        ->and($team['allowedModes'])->toBe(['all', 'latest', 'manual'])
        ->and($picked['allowedModes'])->toBe(['manual']);
});

it('validates the stored value against the field', function (array $value, bool $valid): void {
    $a = fieldReview('A');
    $field = (new S)->collection('reviews')->of('review')->max(2)->modes(['manual', 'latest']);
    $value = json_decode(str_replace('{A}', (string) $a->id, json_encode($value)), true);

    expect(Validator::make(['reviews' => $value], ['reviews' => $field->ruleEntries()])->passes())->toBe($valid);
})->with([
    'untouched' => [[], true],
    'manual pick' => [['mode' => 'manual', 'ids' => ['{A}']], true],
    'latest within max' => [['mode' => 'latest', 'limit' => 2], true],
    'latest over max' => [['mode' => 'latest', 'limit' => 3], false],
    'mode not allowed' => [['mode' => 'all'], false],
    'unknown mode' => [['mode' => 'random'], false],
    'missing entry' => [['mode' => 'manual', 'ids' => ['999']], false],
    'too many picks' => [['mode' => 'manual', 'ids' => ['{A}', '{A}', '{A}']], false],
    'not a list' => [['mode' => 'manual', 'ids' => 'x'], false],
]);

it('types the prop from the collection fields for cms:blocks:types', function (): void {
    $list = (new S)->collection('team')->of('team');
    $one = (new S)->collection('team')->of('team')->single();

    expect($list->phpType())->toBe('list<array{id: int, name: string|null, role: string|null}>')
        ->and($one->phpType())->toBe('array{id: int, name: string|null, role: string|null}|null');
});

it('needs a collection key and stays one value for every locale', function (): void {
    expect(fn () => (new S)->collection('x')->toNode())->toThrow(InvalidArgumentException::class, "needs ->of('<collection key>')")
        ->and(fn () => (new S)->collection('x')->of('review')->translatable())->toThrow(InvalidArgumentException::class, 'not translatable')
        ->and((new S)->collection('x'))->toBeInstanceOf(CollectionField::class);
});
