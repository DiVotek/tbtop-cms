<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\FaqSchemaBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.identity.type', 'organization');
    app(Cms::class)->frontendRoutes();

    // The site root as PageSeeder creates it (is_system + no parent, empty
    // slug path): Breadcrumbs::trailForSchema() prepends it to every other
    // page's trail, which is what gives a top-level `page` a BreadcrumbList.
    Page::factory()->create([
        'status' => PageStatus::Published,
        'is_system' => true,
        'type' => 'page',
        'name' => ['en' => 'Home'],
        'title' => ['en' => 'Home'],
    ]);
});

/** @param array<int, array<string, mixed>> $content */
function blockContentPage(array $content): Page
{
    return Page::factory()->create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Support'],
        'title' => ['en' => 'Support'],
        'content' => $content,
    ]);
}

it('places the FAQ block node after the page node and before identity, matching the canonical', function (): void {
    config(['tbtop-cms.blocks.types' => [FaqSchemaBlock::class]]);
    $page = blockContentPage([[
        'id' => (string) Str::ulid(),
        'kind' => 'FaqTest',
        'active' => true,
        'data' => ['question' => 'Why FAQ?'],
    ]]);

    $response = $this->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])->assertOk();
    $jsonLd = $response->json('props.seo.jsonLd');
    $canonical = $response->json('props.seo.canonical');

    $types = collect($jsonLd)->pluck('@type');
    $faqIndex = $types->search('FAQPage');
    $pageIndex = $types->search('WebPage');
    $identityIndex = $types->search('Organization');

    expect($faqIndex)->not->toBeFalse()
        ->and($pageIndex)->not->toBeFalse()
        ->and($identityIndex)->not->toBeFalse()
        ->and($faqIndex)->toBeGreaterThan($pageIndex)
        ->and($faqIndex)->toBeLessThan($identityIndex);

    $faqNode = collect($jsonLd)->firstWhere('@type', 'FAQPage');
    expect($faqNode['mainEntityOfPage'])->toBe($canonical);
});

it('publishes the same @type list for a Hero-only page as for a blockless page', function (): void {
    config(['tbtop-cms.blocks.types' => [HeroBlock::class]]);
    $withHero = blockContentPage([[
        'id' => (string) Str::ulid(),
        'kind' => 'Hero',
        'active' => true,
        'data' => ['title' => 'Welcome'],
    ]]);
    $withoutBlocks = blockContentPage([]);

    $withHeroTypes = $this->get('/' . $withHero->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.seo.jsonLd');
    $withoutBlocksTypes = $this->get('/' . $withoutBlocks->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.seo.jsonLd');

    $expected = ['WebSite', 'BreadcrumbList', 'WebPage', 'Organization'];

    expect(collect($withHeroTypes)->pluck('@type')->all())->toBe($expected)
        ->and(collect($withoutBlocksTypes)->pluck('@type')->all())->toBe($expected);
});
