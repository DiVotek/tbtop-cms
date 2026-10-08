<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/*
 * The PHP half of the resolve() ↔ zod parity check. The props the package's
 * own blocks reach the client with, through the real page route, are written
 * to a JSON fixture; resources/js/cms/blockProps.parity.test.ts validates
 * every entry against the generated zod schema. CI's frontend job has no PHP,
 * so the fixture is committed and this test fails when it is stale —
 * rerun with UPDATE_BLOCK_FIXTURES=1 to rewrite it.
 */

uses(RefreshDatabase::class);

const PARITY_FIXTURE = __DIR__ . '/../../resources/js/cms/blockProps.fixture.json';

/** @return array<string, array<string, array<string, mixed>>> stored block data by kind, then by case name */
function parityCases(): array
{
    return [
        'Hero' => [
            'full' => [
                'title' => ['en' => 'Welcome'],
                'subtitle' => ['en' => 'A subtitle'],
                'image' => 'https://example.test/hero.jpg',
                'ctaLabel' => ['en' => 'Go'],
                'ctaHref' => '/contact',
            ],
            'empty' => [],
        ],
        'ContactForm' => [
            'full' => [
                'heading' => ['en' => 'Write to us'],
                'submitLabel' => ['en' => 'Send it'],
                'successMessage' => ['en' => 'Got it.'],
            ],
            'empty' => [],
        ],
    ];
}

it('ships the package blocks with props that match the committed parity fixture', function (): void {
    config(['cache.default' => 'array']);
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class, ContactForm::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();

    $cases = parityCases();
    expect(array_keys($cases))->toEqualCanonicalizing(app(BlockRegistry::class)->kinds());

    $content = [];
    foreach ($cases as $kind => $inputs) {
        foreach ($inputs as $case => $data) {
            $content[] = ['id' => "{$kind}-{$case}", 'kind' => $kind, 'data' => $data];
        }
    }
    // The page id ends up in the ContactForm action URL; MySQL keeps AUTO_INCREMENT across rollbacks.
    Page::factory()->create(['id' => 1, 'status' => PageStatus::Published, 'name' => ['en' => 'Parity'], 'content' => $content]);

    $elements = $this->get('/parity', ['X-Inertia' => 'true'])->assertOk()->json('props.render.elements');

    $fixture = [];
    foreach ($cases as $kind => $inputs) {
        foreach (array_keys($inputs) as $case) {
            expect($elements["{$kind}-{$case}"]['type'])->toBe($kind);
            $fixture[$kind][$case] = $elements["{$kind}-{$case}"]['props'];
        }
    }
    $json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

    if (getenv('UPDATE_BLOCK_FIXTURES') === '1' || ! is_file(PARITY_FIXTURE)) {
        file_put_contents(PARITY_FIXTURE, $json);
    }

    expect(file_get_contents(PARITY_FIXTURE))->toBe($json, 'blockProps.fixture.json is stale: rerun this test with UPDATE_BLOCK_FIXTURES=1.');
});
