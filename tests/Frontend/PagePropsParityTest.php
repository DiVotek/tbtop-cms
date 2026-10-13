<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\BlockTypes\PageTypeScriptEmitter;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\ConcertPageType;
use Tbtop\Cms\Tests\Fixtures\EventPageType;
use Tbtop\Cms\Tests\Fixtures\FestivalPageType;
use Tbtop\Cms\Tests\Fixtures\GigPageType;
use Tbtop\Cms\Tests\Fixtures\LegalServicePageType;
use Tbtop\Cms\Tests\Fixtures\ShadowingSubtypePageType;
use Tbtop\Cms\Tests\Fixtures\SpecialistPageType;

/*
 * The PHP half of the page props ↔ zod parity check, like BlockPropsParityTest
 * for blocks: the `page` prop of a real page of every fixture type and
 * subtype is written to a JSON fixture, and
 * resources/js/cms/pageProps.parity.test.ts validates each one against
 * resources/js/cms/testing/pages.generated.ts (written by
 * `composer types:blocks` from the same types). The fixture is committed and
 * this test fails when it is stale — rerun with UPDATE_BLOCK_FIXTURES=1.
 */

uses(RefreshDatabase::class);

const PAGE_PARITY_FIXTURE = __DIR__ . '/../../resources/js/cms/testing/pageProps.fixture.json';

const PAGE_PARITY_MEDIA_ID = 7;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    // The same types bin/generate-block-types.php writes resources/js/cms/testing/pages.generated.ts from.
    config()->set('tbtop-cms.pages.types', [
        EventPageType::class, ConcertPageType::class, FestivalPageType::class, GigPageType::class,
        ShadowingSubtypePageType::class, SpecialistPageType::class, LegalServicePageType::class,
    ]);
    Storage::fake('public');
    Language::factory()->default()->create(['locale' => 'en']);
    Media::create(['id' => PAGE_PARITY_MEDIA_ID, 'name' => 'photo.png', 'disk' => 'public', 'path' => 'media/photo.png', 'mime' => 'image/png', 'size' => 1]);
    app(Cms::class)->frontendRoutes();
});

/**
 * Stored attributes by type or subtype key, then by case name. Ids are explicit: they reach the props, and MySQL keeps
 * AUTO_INCREMENT across rollbacks. Articles hang under the category (id 10). The full specialist references the full
 * legal service, which so lists it back.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function pageParityCases(): array
{
    $paragraph = ['root' => ['type' => 'root', 'children' => [
        ['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'Body', 'format' => 0]]],
    ]]];
    $article = static fn (int $id, string $name, ?string $subtype, array $fields = []): array => [
        'id' => $id, 'type' => 'article', 'subtype' => $subtype, 'parent_id' => 10, 'name' => ['en' => $name], 'fields' => $fields,
    ];
    $event = static fn (int $id, string $name, ?string $subtype, array $fields = []): array => [
        'id' => $id, 'type' => 'event', 'subtype' => $subtype, 'name' => ['en' => $name], 'fields' => $fields,
    ];

    return [
        'page' => [
            'full' => [
                'id' => 1, 'type' => 'page', 'name' => ['en' => 'Full page'], 'title' => ['en' => 'Title'], 'heading' => ['en' => 'Heading'],
                'summary' => ['en' => 'Summary'], 'body' => ['en' => $paragraph], 'image' => (string) PAGE_PARITY_MEDIA_ID,
            ],
            'empty' => ['id' => 2, 'type' => 'page', 'name' => ['en' => 'Empty page']],
        ],
        'category' => ['empty' => ['id' => 10, 'type' => 'category', 'name' => ['en' => 'Team']]],
        'article' => ['empty' => $article(11, 'Plain article', null)],
        'event' => [
            'full' => $event(20, 'Full event', null, ['venue' => ['en' => 'Arena'], 'capacity' => '500']),
            'empty' => $event(21, 'Empty event', null),
        ],
        'concert' => [
            'full' => $event(30, 'Full concert', 'concert', ['venue' => ['en' => 'Hall'], 'capacity' => '80', 'artist' => 'Muse']),
            'empty' => $event(31, 'Empty concert', 'concert'),
        ],
        'festival' => [
            'full' => $event(40, 'Full festival', 'festival', ['venue' => ['en' => 'Field'], 'capacity' => '9000', 'headliner' => 'Blur']),
            'empty' => $event(41, 'Empty festival', 'festival'),
        ],
        'gig' => [
            'full' => $event(50, 'Full gig', 'gig', ['venue' => ['en' => 'Pub'], 'capacity' => '40', 'band' => 'Pulp']),
            'empty' => $event(51, 'Empty gig', 'gig'),
        ],
        'shadowing' => ['empty' => $event(60, 'Shadowing', 'shadowing', ['venue' => ['en' => 'Shadowed']])],
        'specialist' => [
            'full' => $article(70, 'Anna', 'specialist', ['services' => ['80']]),
            'empty' => $article(71, 'Bob', 'specialist'),
        ],
        'legal_service' => [
            'full' => $article(80, 'Divorce', 'legal_service'),
            'empty' => $article(81, 'Contracts', 'legal_service'),
        ],
    ];
}

/**
 * Every case as a published page (forceCreate: the ids are not fillable), fetched through the real page route.
 * Decoded to objects, not arrays: an empty `fields` reaches the client as {}, and re-encoding an array would write [].
 *
 * @return array<string, array<string, stdClass>> the `page` prop by key, then by case name
 */
function renderPageParityProps(): array
{
    $cases = pageParityCases();
    $pages = [];
    foreach ($cases as $key => $inputs) {
        foreach ($inputs as $case => $attributes) {
            $pages[$key][$case] = Page::forceCreate(['status' => PageStatus::Published, ...$attributes]);
        }
    }

    $props = [];
    foreach ($pages as $key => $inputs) {
        foreach ($inputs as $case => $page) {
            $response = test()->get('/' . $page->refresh()->pathFor('en'), ['X-Inertia' => 'true'])->assertOk();
            $props[$key][$case] = json_decode((string) $response->getContent(), flags: JSON_THROW_ON_ERROR)->props->page;
        }
    }

    return $props;
}

it('renders every fixture page type to a page prop that matches the committed parity fixture', function (): void {
    expect(array_keys(pageParityCases()))->toEqualCanonicalizing(app(PageTypeRegistry::class)->keys());

    $props = renderPageParityProps();

    foreach (array_keys(PageTypeScriptEmitter::commonFields()) as $name) {
        expect($props['page']['full']->{$name})->not->toBeIn([null, ''], "page.full.{$name} resolved empty: fill it in pageParityCases().");
    }
    foreach ($props as $key => $cases) {
        foreach (isset($cases['full']) ? (array) $cases['full']->fields : [] as $name => $value) {
            expect($value)->not->toBeIn([null, []], "{$key}.full.fields.{$name} resolved empty: fill it in pageParityCases().");
        }
    }

    $json = json_encode($props, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

    if (getenv('UPDATE_BLOCK_FIXTURES') === '1') {
        file_put_contents(PAGE_PARITY_FIXTURE, $json);
    }

    expect(is_file(PAGE_PARITY_FIXTURE))->toBeTrue('pageProps.fixture.json is missing: run this test with UPDATE_BLOCK_FIXTURES=1 and commit it.')
        ->and(file_get_contents(PAGE_PARITY_FIXTURE))->toBe($json, 'pageProps.fixture.json is stale: rerun this test with UPDATE_BLOCK_FIXTURES=1.');
});

it('types every key of the page prop the controller sends', function (): void {
    $page = Page::create(['status' => PageStatus::Published, 'type' => 'page', 'name' => ['en' => 'Keys']]);

    $sent = test()->get('/' . $page->refresh()->pathFor('en'), ['X-Inertia' => 'true'])->assertOk()->json('props.page');

    expect(array_keys($sent))->toEqualCanonicalizing([...array_keys(PageTypeScriptEmitter::commonFields()), 'type', 'subtype', 'fields']);
});
