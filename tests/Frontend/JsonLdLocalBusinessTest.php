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
    $settings->set('site.name', 'Autofocus');
    $settings->set('seo.identity.type', 'local_business');
    $settings->set('seo.identity.subtype', 'AutoRepair');

    app(Cms::class)->frontendRoutes();
});

/**
 * The main office (the first published one) unless $sort says otherwise.
 *
 * @param  array<string, mixed>  $data
 */
function identityOffice(array $data = [], int $sort = 0): Entry
{
    return Entry::query()->create([
        'collection' => 'offices',
        'status' => Entry::PUBLISHED,
        'sort_order' => $sort,
        'data' => ['name' => ['en' => 'Main'], 'street' => ['en' => '1 Main St'], 'locality' => ['en' => 'Kyiv'], ...$data],
    ]);
}

function identitySocial(string $label, string $href, ?string $name = null): Entry
{
    return Entry::query()->create([
        'collection' => 'socials',
        'status' => Entry::PUBLISHED,
        'data' => ['label' => $label, 'name' => $name, 'href' => $href],
    ]);
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
    identityOffice([
        'phones' => [['number' => '+380 44 123 4567']],
        'opening_hours' => [['days' => ['Monday', 'Friday'], 'opens' => '09:00', 'closes' => '18:00']],
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
    identityOffice(['street' => ['en' => '1 Main St', 'uk' => 'вул. Головна, 1']]);
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

    expect($node['author'])->toBe(['@type' => $schemaType, 'name' => 'Autofocus', '@id' => $id]);

    if ($hasPublisher) {
        expect($node['publisher']['@type'])->toBe($schemaType)
            ->and($node['publisher']['name'])->toBe('Autofocus')
            ->and($node['publisher']['@id'])->toBe($id);
    } else {
        expect($node)->not->toHaveKey('publisher');
    }
})->with([
    'local business' => ['local_business', 'AutoRepair', true],
    'person' => ['person', 'Person', false],
]);

it('takes telephone and email from the main office and sameAs from the social links', function (): void {
    identityOffice(['phones' => [['number' => '+380 44 555 0001'], ['number' => '+380 44 555 0002']], 'emails' => [['address' => 'hello@example.com']]]);
    identitySocial('FB', 'https://facebook.com/acme', 'Facebook');
    identitySocial('TG', 'https://t.me/acme');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $identity = identityNodes(localBusinessJsonLd('/about'));

    expect($identity)->toHaveCount(1)
        ->and($identity[0]['telephone'])->toBe('+380 44 555 0001')
        ->and($identity[0]['email'])->toBe('hello@example.com')
        ->and($identity[0]['sameAs'])->toBe(['https://facebook.com/acme', 'https://t.me/acme']);
});

it('gives an Organization the telephone of the main office when nothing else is set', function (): void {
    app(Settings::class)->set('seo.identity.type', 'organization');
    identityOffice(['phones' => [['number' => '+380 44 555 0001']]]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $identity = identityNodes(localBusinessJsonLd('/about'));

    expect($identity[0]['@type'])->toBe('Organization')
        ->and($identity[0]['telephone'])->toBe('+380 44 555 0001');
});

it('no longer reads the legacy SEO identity telephone, email and profile links', function (): void {
    $settings = app(Settings::class);
    identityOffice(['phones' => [['number' => '+380 44 555 0001']], 'emails' => [['address' => 'hello@example.com']]]);
    identitySocial('FB', 'https://facebook.com/acme');
    $settings->set('seo.identity.telephone', '+380 44 123 4567');
    $settings->set('seo.identity.email', 'press@example.com');
    $settings->set('seo.identity.same_as', ['https://www.wikidata.org/wiki/Q1']);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $identity = identityNodes(localBusinessJsonLd('/about'));

    expect($identity[0]['telephone'])->toBe('+380 44 555 0001')
        ->and($identity[0]['email'])->toBe('hello@example.com')
        ->and($identity[0]['sameAs'])->toBe(['https://facebook.com/acme']);
});

it('publishes the other offices as departments of the identity, drafts left out', function (): void {
    identityOffice(['phones' => [['number' => '+380 44 000 0001']]]);
    identityOffice(['name' => ['en' => 'Lviv branch'], 'street' => ['en' => '2 Market Sq'], 'locality' => ['en' => 'Lviv'], 'phones' => [['number' => '+380 32 000 0002']], 'latitude' => 49.84, 'longitude' => 24.03], 1);
    Entry::query()->create(['collection' => 'offices', 'status' => Entry::DRAFT, 'sort_order' => 2, 'data' => ['name' => ['en' => 'Soon']]]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $identity = identityNodes(localBusinessJsonLd('/about'))[0];

    expect($identity['telephone'])->toBe('+380 44 000 0001')
        ->and($identity['address']['addressLocality'])->toBe('Kyiv')
        ->and($identity['department'])->toBe([[
            '@type' => 'AutoRepair',
            'name' => 'Lviv branch',
            'telephone' => '+380 32 000 0002',
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => '2 Market Sq', 'addressLocality' => 'Lviv'],
            'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 49.84, 'longitude' => 24.03],
        ]]);
});
