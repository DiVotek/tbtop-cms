<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Language::factory()->default()->create(['locale' => 'en']);

    $settings = app(Settings::class);
    $settings->set('site.name', 'Acme Repair');
    $settings->set('seo.identity.type', 'local_business');
    $settings->set('seo.identity.subtype', 'AutoRepair');
    $settings->set('seo.identity.address.street', ['en' => '1 Main St']);
    $settings->set('seo.identity.address.locality', ['en' => 'Kyiv']);

    app(Cms::class)->frontendRoutes();
});

/** @param  list<array<string, mixed>>  $rows  published entries, in this order */
function contactEntries(string $collection, array $rows): void
{
    foreach ($rows as $i => $data) {
        Entry::query()->create(['collection' => $collection, 'status' => Entry::PUBLISHED, 'sort_order' => $i, 'data' => $data]);
    }
}

/** @return list<array<string, mixed>> */
function localBusinessJsonLd(string $path): array
{
    return test()->get($path, ['X-Inertia' => 'true'])->assertOk()->json('props.seo.jsonLd');
}

/**
 * @param  list<array<string, mixed>>  $jsonLd
 * @return list<array<string, mixed>>
 */
function identityNodes(array $jsonLd): array
{
    return array_values(array_filter($jsonLd, static fn (array $node): bool => ($node['@id'] ?? null) === url('/') . '#identity'));
}

it('publishes a local business as the single identity node with address, telephone and opening hours', function (): void {
    $settings = app(Settings::class);
    contactEntries('phones', [['number' => '+380 44 123 4567']]);
    $settings->set('seo.identity.opening_hours', [
        ['days' => ['Monday', 'Friday'], 'opens' => '09:00', 'closes' => '18:00'],
    ]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $jsonLd = localBusinessJsonLd('/about');
    $identity = identityNodes($jsonLd);

    expect($identity)->toHaveCount(1)
        ->and($identity[0]['@type'])->toBe('AutoRepair')
        ->and($identity[0]['address'])->toBe([
            '@type' => 'PostalAddress',
            'streetAddress' => '1 Main St',
            'addressLocality' => 'Kyiv',
        ])
        ->and($identity[0]['telephone'])->toBe('+380 44 123 4567')
        ->and($identity[0]['openingHoursSpecification'])->toBe([[
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => ['https://schema.org/Monday', 'https://schema.org/Friday'],
            'opens' => '09:00',
            'closes' => '18:00',
        ]])
        ->and(collect($jsonLd)->pluck('@type')->all())->not->toContain('Organization');
});

it('falls back to the default locale for an untranslated address part', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Settings::class)->set('seo.identity.address.street', ['en' => '1 Main St', 'uk' => 'вул. Головна, 1']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
    ]);

    $en = identityNodes(localBusinessJsonLd('/about'))[0]['address'];
    $uk = identityNodes(localBusinessJsonLd('/uk/' . $page->pathFor('uk')))[0]['address'];

    expect($en['streetAddress'])->toBe('1 Main St')
        ->and($uk['streetAddress'])->toBe('вул. Головна, 1')
        ->and($en['addressLocality'])->toBe('Kyiv')
        ->and($uk['addressLocality'] ?? null)->toBe('Kyiv');
});

it('links the article author and publisher to the identity node', function (string $type, string $schemaType, bool $hasPublisher): void {
    app(Settings::class)->set('seo.identity.type', $type);
    $blog = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Blog']]);
    $article = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $blog->getKey(),
        'name' => ['en' => 'Post'],
    ]);

    $node = collect(localBusinessJsonLd('/' . $article->pathFor('en')))->firstWhere('@type', 'Article');
    $id = url('/') . '#identity';

    expect($node['author'])->toBe(['@type' => $schemaType, 'name' => 'Acme Repair', '@id' => $id]);

    if ($hasPublisher) {
        expect($node['publisher']['@type'])->toBe($schemaType)
            ->and($node['publisher']['name'])->toBe('Acme Repair')
            ->and($node['publisher']['@id'])->toBe($id);
    } else {
        expect($node)->not->toHaveKey('publisher');
    }
})->with([
    'local business' => ['local_business', 'AutoRepair', true],
    'person' => ['person', 'Person', false],
]);

it('takes telephone, email and sameAs from the built-in contact collections', function (): void {
    contactEntries('phones', [['number' => '+380 44 555 0001'], ['number' => '+380 44 555 0002']]);
    contactEntries('emails', [['address' => 'hello@example.com']]);
    contactEntries('socials', [
        ['label' => 'FB', 'name' => 'Facebook', 'href' => 'https://facebook.com/acme'],
        ['label' => 'TG', 'href' => 'https://t.me/acme'],
    ]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $identity = identityNodes(localBusinessJsonLd('/about'));

    expect($identity)->toHaveCount(1)
        ->and($identity[0]['telephone'])->toBe('+380 44 555 0001')
        ->and($identity[0]['email'])->toBe('hello@example.com')
        ->and($identity[0]['sameAs'])->toBe(['https://facebook.com/acme', 'https://t.me/acme']);
});

it('gives an Organization the first phone when nothing else is set', function (): void {
    $settings = app(Settings::class);
    $settings->set('seo.identity.type', 'organization');
    contactEntries('phones', [['number' => '+380 44 555 0001']]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $identity = identityNodes(localBusinessJsonLd('/about'));

    expect($identity[0]['@type'])->toBe('Organization')
        ->and($identity[0]['telephone'])->toBe('+380 44 555 0001');
});

it('no longer reads the legacy SEO identity telephone, email and profile links', function (): void {
    $settings = app(Settings::class);
    contactEntries('phones', [['number' => '+380 44 555 0001']]);
    contactEntries('emails', [['address' => 'hello@example.com']]);
    contactEntries('socials', [['label' => 'FB', 'href' => 'https://facebook.com/acme']]);
    $settings->set('seo.identity.telephone', '+380 44 123 4567');
    $settings->set('seo.identity.email', 'press@example.com');
    $settings->set('seo.identity.same_as', ['https://www.wikidata.org/wiki/Q1']);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $identity = identityNodes(localBusinessJsonLd('/about'));

    expect($identity[0]['telephone'])->toBe('+380 44 555 0001')
        ->and($identity[0]['email'])->toBe('hello@example.com')
        ->and($identity[0]['sameAs'])->toBe(['https://facebook.com/acme']);
});
