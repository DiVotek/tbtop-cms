<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\Tests\Fixtures\BrokenBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class, BrokenBlock::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('assembles resolved blocks into a json-render spec', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [
            ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Welcome']]],
            ['id' => 'hero-2', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Second']]],
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Cms/Page')
        ->assertJsonPath('props.render.root', '$root')
        ->assertJsonPath('props.render.elements.$root.children', ['hero-1', 'hero-2'])
        ->assertJsonPath('props.render.elements.hero-1.type', 'Hero')
        ->assertJsonPath('props.render.elements.hero-1.props.title', 'Welcome')
        ->assertJsonPath('props.render.elements.hero-2.props.title', 'Second');
});

it('orders rendered blocks by sort, not by their position in content', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [
            ['id' => 'hero-1', 'kind' => 'Hero', 'sort' => 2, 'data' => ['title' => ['en' => 'Second']]],
            ['id' => 'hero-2', 'kind' => 'Hero', 'sort' => 1, 'data' => ['title' => ['en' => 'First']]],
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.$root.children', ['hero-2', 'hero-1'])
        ->assertJsonPath('props.render.elements.hero-2.props.title', 'First')
        ->assertJsonPath('props.render.elements.hero-1.props.title', 'Second');
});

it('drops a block that throws while resolving and keeps its siblings', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [
            ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Welcome']]],
            ['id' => 'broken-1', 'kind' => 'Broken', 'data' => []],
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.$root.children', ['hero-1'])
        ->assertJsonMissingPath('props.render.elements.broken-1');
});

it('ships page.fields as a JSON object when it is empty', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Welcome']]]],
    ]);

    $props = json_decode($this->get('/about', ['X-Inertia' => 'true'])->assertOk()->getContent(), false)->props;

    expect($props->page->fields)->toEqual(new stdClass);
});

it('skips an unregistered kind and a dangling shared reference without a trace on the page or in the log', function (): void {
    $gone = SharedBlock::query()->create(['name' => 'Promo', 'kind' => 'Hero', 'data' => []]);
    $goneId = $gone->getKey();
    $gone->delete();
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [
            ['id' => 'ghost-1', 'kind' => 'Ghost', 'data' => ['legacy' => 'kept']],
            ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Welcome']]],
            ['id' => 'dangling-1', 'kind' => 'Hero', 'shared' => $goneId, 'data' => []],
        ],
    ]);
    Log::spy();

    $render = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->json('props.render');

    expect($render)->toHaveKeys(['root', 'elements'])->not->toHaveKey('errors')
        ->and(array_keys($render['elements']))->toEqualCanonicalizing(['hero-1', '$root'])
        ->and($render['elements']['$root']['children'])->toBe(['hero-1']);
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('renders a valid empty spec for a page with no blocks', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.root', '$root')
        ->assertJsonPath('props.render.elements.$root.children', []);
});

it('drops a block whose id collides with the reserved root id', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [
            ['id' => '$root', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Evil']]],
            ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Welcome']]],
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.root', '$root')
        ->assertJsonPath('props.render.elements.$root.type', 'Fragment')
        ->assertJsonPath('props.render.elements.$root.children', ['hero-1']);
});

it('drops a block with a duplicate id, keeping the first occurrence', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [
            ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'First']]],
            ['id' => 'hero-1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Second']]],
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.$root.children', ['hero-1'])
        ->assertJsonPath('props.render.elements.hero-1.props.title', 'First');
});

it('ships the Body as HTML in page props, falling back to the default locale when the active one is blank', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $paragraph = static fn (string $text): array => [
        'root' => ['type' => 'root', 'children' => [
            ['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => $text, 'format' => 0]]],
        ]],
    ];
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        // The editor saves an empty document for every locale it showed.
        'body' => ['en' => $paragraph('English body'), 'uk' => ['root' => ['type' => 'root', 'children' => []]]],
        'content' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.page.body', '<p>English body</p>');
    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.page.body', '<p>English body</p>');
});

it('falls back to the default-locale Body when the active one was typed and then erased', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $document = static fn (array $paragraphs): array => ['root' => ['type' => 'root', 'children' => array_map(
        static fn (array $children): array => ['type' => 'paragraph', 'children' => $children],
        $paragraphs,
    )]];
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        // Lexical keeps one empty paragraph after the text is erased; spaces and a bare line break are blank too.
        'body' => [
            'en' => $document([[['type' => 'text', 'text' => 'English body', 'format' => 0]]]),
            'uk' => $document([[], [['type' => 'text', 'text' => '  ', 'format' => 0], ['type' => 'linebreak']]]),
        ],
        'content' => [],
    ]);

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.page.body', '<p>English body</p>');
});
