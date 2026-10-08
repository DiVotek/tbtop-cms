<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\FormResolver\ArrayShapesBlock;
use Tbtop\Cms\Tests\Fixtures\FormResolver\EveryKindBlock;

/*
 * The PHP half of the resolve() ↔ zod parity check. The props the package's
 * own blocks reach the client with, through the real page route, are written
 * to a JSON fixture; resources/js/cms/blockProps.parity.test.ts validates
 * every entry against the generated zod schema. CI's frontend job has no PHP,
 * so the fixture is committed and this test fails when it is stale —
 * rerun with UPDATE_BLOCK_FIXTURES=1 to rewrite it.
 *
 * The form-driven fixture blocks (no resolve() of their own) get the same
 * check against resources/js/cms/testing/ (same bun test): one field per
 * FormResolver rule, schema written by `composer types:blocks`, props fixture
 * written here.
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

const FORM_PARITY_FIXTURE = __DIR__ . '/../../resources/js/cms/testing/formBlockProps.fixture.json';

// Explicit keys: the media id and the referenced page id end up in the props; MySQL keeps AUTO_INCREMENT across rollbacks.
const FORM_PARITY_MEDIA_ID = 7;

const FORM_PARITY_OTHER_PAGE_ID = 2;

/**
 * Stored data for the form-driven fixture blocks by kind, then by case name. In `full`, every
 * list-producing field has a dropped item before a kept one, so a list that loses its
 * array_values() reaches the client as an object.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function formParityCases(): array
{
    $richtext = ['root' => ['children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'Hi', 'format' => 0]]]]]];

    return [
        'EveryKind' => [
            'full' => [
                'title' => ['en' => 'Hello'],
                'note' => 'A note',
                'body' => $richtext,
                'image' => FORM_PARITY_MEDIA_ID,
                'gallery' => [999999, FORM_PARITY_MEDIA_ID],
                'cta' => ['type' => 'external', 'value' => 'https://example.com', 'label' => ['en' => 'Go']],
                'count' => '12',
                'level' => '3.5',
                'featured' => true,
                'agreed' => '1',
                'keywords' => ['', 'a'],
                'perks' => ['', 'wifi'],
                'sizes' => ['', 'm'],
                'size' => 'm',
                'tone' => 'warm',
                'day' => '2026-01-02',
                'color' => '#ff0000',
                'meta' => ['k' => 'v'],
                'related' => ['999999', (string) FORM_PARITY_OTHER_PAGE_ID],
                'items' => [
                    ['label' => ['en' => ''], 'qty' => null],
                    ['label' => ['en' => 'One'], 'qty' => '2'],
                ],
            ],
            'empty' => [],
        ],
        'ArrayShapes' => [
            'full' => [
                'days' => ['', 'mon'],
                'day' => 'tue',
                'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
                'files' => ['', 'uploads/a.pdf'],
                'file' => 'uploads/b.pdf',
                'address' => ['street' => 'Main 1'],
                'sections' => [
                    ['heading' => ['en' => ''], 'rows' => []],
                    ['heading' => ['en' => 'Intro'], 'rows' => [
                        ['cell' => ['en' => ''], 'span' => null],
                        ['cell' => ['en' => 'A'], 'span' => '2'],
                    ]],
                ],
            ],
            'empty' => [],
        ],
    ];
}

/**
 * Fails on a prop that came out empty — null, [] or false — here, inside nested objects and in the first row of each nested list.
 *
 * @param  array<array-key, mixed>  $props
 */
function expectFormParityFilled(array $props, string $path): void
{
    foreach ($props as $key => $value) {
        expect($value)->not->toBeIn([null, [], false], "{$path}.{$key} resolved empty in the full case: give it a non-empty value in formParityCases(), then rerun with UPDATE_BLOCK_FIXTURES=1.");

        if (is_array($value) && ! array_is_list($value)) {
            expectFormParityFilled($value, "{$path}.{$key}");
        } elseif (is_array($value) && is_array($value[0] ?? null)) {
            expectFormParityFilled($value[0], "{$path}.{$key}.0");
        }
    }
}

/**
 * Renders every case of every kind as one published page (id 1) through the real page route.
 *
 * @param  array<string, array<string, array<string, mixed>>>  $cases
 * @return array<string, array<string, mixed>> resolved props by kind, then by case name
 */
function renderParityProps(array $cases): array
{
    expect(array_keys($cases))->toEqualCanonicalizing(app(BlockRegistry::class)->kinds());

    $content = [];
    foreach ($cases as $kind => $inputs) {
        foreach ($inputs as $case => $data) {
            $content[] = ['id' => "{$kind}-{$case}", 'kind' => $kind, 'data' => $data];
        }
    }
    // The page id ends up in the ContactForm action URL; MySQL keeps AUTO_INCREMENT across rollbacks.
    Page::factory()->create(['id' => 1, 'status' => PageStatus::Published, 'name' => ['en' => 'Parity'], 'content' => $content]);

    $elements = test()->get('/parity', ['X-Inertia' => 'true'])->assertOk()->json('props.render.elements');

    $props = [];
    foreach ($cases as $kind => $inputs) {
        foreach (array_keys($inputs) as $case) {
            $id = "{$kind}-{$case}";
            expect(array_key_exists($id, $elements))->toBeTrue("{$id} did not render: its resolve() threw or the block was hidden.");
            expect($elements[$id]['type'])->toBe($kind);
            $props[$kind][$case] = $elements[$id]['props'];
        }
    }

    return $props;
}

/** @param  array<string, array<string, mixed>>  $props */
function expectParityFixture(string $path, array $props): void
{
    $json = json_encode($props, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

    if (getenv('UPDATE_BLOCK_FIXTURES') === '1') {
        file_put_contents($path, $json);
    }

    expect(is_file($path))->toBeTrue(basename($path) . ' is missing: run this test with UPDATE_BLOCK_FIXTURES=1 and commit it.')
        ->and(file_get_contents($path))->toBe($json, basename($path) . ' is stale: rerun this test with UPDATE_BLOCK_FIXTURES=1.');
}

it('ships the package blocks with props that match the committed parity fixture', function (): void {
    config(['cache.default' => 'array']);
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class, ContactForm::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();

    expectParityFixture(PARITY_FIXTURE, renderParityProps(parityCases()));
});

it('resolves the form-driven fixture blocks to props that match the committed parity fixture', function (): void {
    config(['cache.default' => 'array']);
    config()->set('tbtop-cms.blocks.types', [EveryKindBlock::class, ArrayShapesBlock::class]);
    Storage::fake('public');
    Language::factory()->default()->create(['locale' => 'en']);
    Media::create(['id' => FORM_PARITY_MEDIA_ID, 'name' => 'photo.png', 'disk' => 'public', 'path' => 'media/photo.png', 'mime' => 'image/png', 'size' => 1]);
    Page::factory()->create(['id' => FORM_PARITY_OTHER_PAGE_ID, 'status' => PageStatus::Published, 'name' => ['en' => 'Other']]);
    app(Cms::class)->frontendRoutes();

    $props = renderParityProps(formParityCases());
    foreach ($props as $kind => $cases) {
        expectFormParityFilled($cases['full'], $kind);
    }

    expectParityFixture(FORM_PARITY_FIXTURE, $props);
});
