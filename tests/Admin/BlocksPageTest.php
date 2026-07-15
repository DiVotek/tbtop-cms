<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\BlockShowcase;
use Tbtop\Cms\Admin\Pages\Support\BlockShowcaseHtml;
use Tbtop\Cms\Admin\Pages\Support\BlockUsage;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [HeroBlock::class, ContactForm::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeBlocksAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);

    return $user;
}

it('describes a block from its metadata and form fields', function (): void {
    $info = BlockShowcase::describe(new HeroBlock);

    expect($info['kind'])->toBe('Hero')
        ->and($info['label'])->toBe('Hero')
        ->and($info['description'])->not->toBeNull()
        ->and($info['category'])->toBe('content')
        ->and($info['fields'])->toHaveCount(5);

    $title = $info['fields'][0];
    expect($title['name'])->toBe('title')
        ->and($title['label'])->toBe('Title')
        ->and($title['type'])->toBe('text')
        ->and($title['required'])->toBeTrue()
        ->and($title['translatable'])->toBeTrue();
});

it('falls back to a headline label when a block declares no metadata', function (): void {
    $block = new class extends Block
    {
        public function kind(): string
        {
            return 'FancyBanner';
        }

        public function form(S $s): array
        {
            return [$s->text('heading')];
        }
    };

    $info = BlockShowcase::describe($block);

    expect($info['label'])->toBe('Fancy Banner')
        ->and($info['description'])->toBeNull()
        ->and($info['category'])->toBeNull();
});

it('escapes block-derived values in the showcase html', function (): void {
    $fields = [
        ['name' => 'x"y', 'label' => '<b>Heading</b>', 'type' => 'text', 'required' => true, 'translatable' => false],
    ];

    $table = BlockShowcaseHtml::fieldsTable(
        $fields,
        'Field',
        'Type',
        'Attributes',
        'required',
        'translatable',
    );
    $preview = BlockShowcaseHtml::placeholder('Ev<il', 'Ev<il block');

    expect($table)->toContain('&lt;b&gt;Heading&lt;/b&gt;')
        ->and($table)->toContain('x&quot;y')
        ->and($table)->not->toContain('<b>Heading</b>')
        ->and($preview)->toContain('Ev&lt;il')
        ->and($preview)->not->toContain('Ev<il<');
});

it('indexes which pages use each block kind, once, in a single pass', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Landing'],
        'content' => [
            ['id' => 'a', 'kind' => 'Hero', 'data' => []],
            ['id' => 'b', 'kind' => 'ContactForm', 'data' => []],
        ],
    ]);
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [['id' => 'c', 'kind' => 'Hero', 'data' => []]],
    ]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Empty'], 'content' => []]);

    $index = BlockUsage::index();

    expect(array_column($index['Hero'], 'name'))->toBe(['Landing', 'About'])
        ->and(array_column($index['ContactForm'], 'name'))->toBe(['Landing'])
        ->and($index['Hero'][0]['url'])->toContain('/edit');
});

it('shows the blocks showcase in the design nav group', function (): void {
    $response = $this->actingAs(makeBlocksAdmin(), 'web')
        ->get('/admin/blocks', ['X-Inertia' => 'true'])
        ->assertOk();

    $response->assertJsonPath('props.title', __('tbtop-cms::blocks_showcase.title'));

    $nav = collect($response->json('props.tbtop.nav'));
    $design = $nav->firstWhere('key', 'design');
    $labels = collect($design['items'])->pluck('label');
    expect($labels)->toContain(__('tbtop-cms::nav.items.blocks'));
});

it('lists every registered block with its fields', function (): void {
    $structure = $this->actingAs(makeBlocksAdmin(), 'web')
        ->get('/admin/blocks', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    $json = json_encode($structure);
    expect($json)->toContain('Hero')
        ->and($json)->toContain('Contact form')
        ->and($json)->toContain('title')
        ->and($json)->toContain('recipient');
});

it('renders an empty state when no blocks are registered', function (): void {
    config(['tbtop-cms.blocks.types' => []]);
    app()->forgetInstance(BlockRegistry::class);

    $structure = $this->actingAs(makeBlocksAdmin(), 'web')
        ->get('/admin/blocks', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    expect(json_encode($structure))->toContain(__('tbtop-cms::blocks_showcase.empty'));
});

it('redirects a guest away from the blocks showcase', function (): void {
    $this->get('/admin/blocks')->assertRedirect(route('login'));
});
