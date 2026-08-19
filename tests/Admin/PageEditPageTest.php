<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\OgPreview;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makePageEditAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

/** @return array<string, mixed> */
function pageEditStructure(int $id): array
{
    return test()->actingAs(makePageEditAdmin(), 'web')
        ->get("/admin/pages/{$id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()
        ->json();
}

/** @param array<string, mixed> $blocks */
function pageWithBlocks(array $blocks = []): Page
{
    return Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About Us'],
        'content' => $blocks,
    ]);
}

it('shows the edit page to an admin user with the record prefilled', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);

    $json = pageEditStructure($page->id);

    expect($json['component'])->toBe('admin/page')
        ->and($json['props']['data']['pageEditor']['name'])->toBe(['en' => 'About Us']);
});

it('returns 404 for a page that does not exist', function (): void {
    test()->actingAs(makePageEditAdmin(), 'web')
        ->get('/admin/pages/999999/edit', ['X-Inertia' => 'true'])
        ->assertNotFound();
});

it('redirects a guest away from the edit page', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    test()->get("/admin/pages/{$page->id}/edit")->assertRedirect(route('login'));
});

it('wires General/SEO tabs, one tab per saved block, and a trailing add-block tab', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Hi']],
    ]);

    $tabs = editFormTabs($page->id);

    expect($tabs)->toHaveCount(4)
        ->and($tabs[0]['label'])->toBe('General')
        ->and($tabs[1]['label'])->toBe('SEO')
        ->and($tabs[2]['label'])->toBe('Simple')
        ->and($tabs[3]['label'])->toBe('+ Add block');

    $generalFieldNames = array_column($tabs[0]['body']['options']['children'], 'name');
    expect($generalFieldNames)->toContain('name', 'title', 'heading', 'summary', 'body')
        ->and($generalFieldNames)->not->toContain('type', 'parent_id', 'status', 'sort_order');

    $seoFieldNames = [];
    collectFieldNames($tabs[1]['body'], $seoFieldNames);
    expect($seoFieldNames)->toContain('seo_title', 'seo_description', 'seo_focus_keyword', 'og_title', 'og_description', 'og_image')
        ->and($seoFieldNames)->not->toContain('is_indexable');
});

it('sends every check to the checklist block, split into required and optional', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Hi']],
    ]);

    $tabs = editFormTabs($page->id);

    $nodes = [];
    collectNodesByKind($tabs[1]['body'], 'cms:seoChecklist', $nodes);
    expect($nodes)->toHaveCount(1);

    $byLocale = $nodes[0]['options']['byLocale'];
    expect($byLocale)->toHaveKey('en')
        ->and($nodes[0]['options']['defaultLocale'])->toBe('en');

    $checks = $byLocale['en'];

    // Focus keyword is unset on this page, so its four presence sub-checks
    // don't fire — the rest are always present.
    expect(array_column($checks, 'key'))->toBe([
        'title', 'description', 'og_image', 'indexable',
        'canonical', 'hreflang', 'focus_keyword', 'duplicate_title',
    ]);

    // The split is what gives the list a reading order; without it the block
    // renders one flat run and the grouping headings sit over nothing.
    $required = array_column(array_filter($checks, static fn (array $c): bool => $c['required']), 'key');
    expect($required)->toBe(['title', 'description', 'indexable', 'canonical', 'duplicate_title']);

    // Status words are translated server-side — the block renders text nodes
    // and has no locale of its own.
    $words = [
        __('tbtop-cms::pages.seo_checklist.status.pass'),
        __('tbtop-cms::pages.seo_checklist.status.warn'),
        __('tbtop-cms::pages.seo_checklist.status.fail'),
    ];
    expect(array_column($checks, 'statusWord'))->each->toBeIn($words);
});

it('resolves the checklist for every admin-active locale, not just the default', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        // en carries nothing at all (name empty too), so its meta-title
        // cascade bottoms out empty; uk has a name to fall back to. Only a
        // per-locale resolve tells the two apart — a checklist copying the
        // default locale's checks would report both the same way.
        'name' => ['en' => '', 'uk' => 'Про нас'],
    ]);

    $tabs = editFormTabs($page->id);
    $nodes = [];
    collectNodesByKind($tabs[1]['body'], 'cms:seoChecklist', $nodes);

    $byLocale = $nodes[0]['options']['byLocale'];
    expect(array_keys($byLocale))->toBe(['en', 'uk'])
        ->and($nodes[0]['options']['defaultLocale'])->toBe('en');

    $enTitle = collect($byLocale['en'])->firstWhere('key', 'title');
    $ukTitle = collect($byLocale['uk'])->firstWhere('key', 'title');
    expect($enTitle['status'])->toBe('fail')
        ->and($ukTitle['status'])->toBe('pass');
});

it('carries the og deps for every locale and the shared og_image dep on the ogPreview live region', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About', 'uk' => 'Про нас']]);

    $tabs = editFormTabs($page->id);
    $regions = [];
    collectNodesByKind($tabs[1]['body'], 'liveRegion', $regions);
    $ogRegion = collect($regions)->firstWhere('name', 'ogPreview');

    expect($ogRegion)->not->toBeNull()
        ->and($ogRegion['options']['dependsOn'])->toBe([
            'og_image',
            'og_title.en', 'og_description.en', 'seo_title.en', 'seo_description.en',
            'og_title.uk', 'og_description.uk', 'seo_title.uk', 'seo_description.uk',
        ]);
});

it('reflects an unsaved seo_title in the ogPreview card while og_title is empty', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $region = collect(OgPreview::build(app(S::class), $page, 'en'))->first();
    $node = collect($region->renderWith(['seo_title.en' => 'Draft meta title', 'og_image' => '']))->first();

    // og:title has no override of its own, so it resolves through the meta
    // title cascade — the card must follow the unsaved meta title, not the
    // saved fallback ("About" from the page name).
    expect($node->options['byLocale']['en']['title'])->toBe('Draft meta title');
});

it('reflects an unsaved og_title override in the ogPreview card, per locale', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $region = collect(OgPreview::build(app(S::class), $page, 'en'))->first();
    $node = collect($region->renderWith(['og_title.en' => 'Draft OG title', 'og_image' => '']))->first();

    expect($node->options['byLocale']['en']['title'])->toBe('Draft OG title')
        // The saved page carries no og_title, so without the overlay this
        // would fall back to the meta title/name cascade ("About") instead.
        ->not->toBe('About');
});

it('resolves image=null on the ogPreview card when og_image is empty and the page has no image', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $region = collect(OgPreview::build(app(S::class), $page, 'en'))->first();
    $node = collect($region->renderWith(['og_image' => '']))->first();

    expect($node->options['image'])->toBeNull();
});

it('omits the SEO checklist section for a null or unsaved page', function (): void {
    $s = app(S::class);

    $wireForNull = json_decode(json_encode($s->stack(PageForm::seoFields($s, null))), true);
    $wireForUnsaved = json_decode(json_encode($s->stack(PageForm::seoFields($s, new Page))), true);

    $displayValuesForNull = [];
    collectNodesByKind($wireForNull, 'displayValue', $displayValuesForNull);

    $displayValuesForUnsaved = [];
    collectNodesByKind($wireForUnsaved, 'displayValue', $displayValuesForUnsaved);

    expect($displayValuesForNull)->toBeEmpty()
        ->and($displayValuesForUnsaved)->toBeEmpty();
});

it('prefixes block fields with block_{id}_ inside the block tab', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Hi', 'body' => 'Text']],
    ]);

    $tabs = editFormTabs($page->id);
    $blockTab = $tabs[2];
    $names = [];
    collectFieldNames($blockTab['body'], $names);

    expect($names)->toContain('block_blk1_heading', 'block_blk1_body', 'block_blk1_active', 'block_blk1_sort');
});

it('orders block tabs by sort, not by their position in content', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 2, 'data' => ['heading' => 'First saved']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'Second saved']],
    ]);

    $tabs = editFormTabs($page->id);

    // Both blocks share the label "Simple", so identify each tab by the
    // block_{id}_sort field it carries rather than by label text.
    $firstNames = [];
    collectFieldNames($tabs[2]['body'], $firstNames);
    $secondNames = [];
    collectFieldNames($tabs[3]['body'], $secondNames);

    expect($firstNames)->toContain('block_blk2_sort')
        ->and($secondNames)->toContain('block_blk1_sort');
});

it('preserves the translatable flag and required rule when renaming a block field', function (): void {
    config(['tbtop-cms.blocks.types' => [HeroBlock::class]]);
    $page = pageWithBlocks([
        ['id' => 'hero1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Welcome']]],
    ]);

    $tabs = editFormTabs($page->id);
    $titleField = collect($tabs[2]['body']['options']['children'])->firstWhere('name', 'block_hero1_title');

    expect($titleField)->not->toBeNull()
        ->and($titleField['options']['translatable'] ?? null)->toBeTrue();
});

it('round-trips a translatable block field through save', function (): void {
    config(['tbtop-cms.blocks.types' => [HeroBlock::class]]);
    $page = pageWithBlocks([
        ['id' => 'hero1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Welcome']]],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'block_hero1_title' => ['en' => 'Updated'], 'block_hero1_active' => true],
    )->assertRedirect();

    expect($page->refresh()->content[0]['data'])->toBe(['title' => ['en' => 'Updated']]);
});

it('delivers server-side validation errors to the Inertia page after a rejected save', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $admin = makePageEditAdmin();

    // The editor submits through Inertia's router: a failed validation is a
    // redirect back, and the client only learns about it from props.errors on
    // the page it lands on. Without that prop Inertia reports success and the
    // form resyncs to the stored record — the user's edit vanishes silently.
    test()->actingAs($admin, 'web')
        ->from("/admin/pages/{$page->id}/edit")
        ->post(
            "/admin/pages/{$page->id}/edit/forms/pageEditor",
            [...baseEditPayload(), 'name' => ['en' => '']],
            ['X-Inertia' => 'true'],
        )
        ->assertRedirect("/admin/pages/{$page->id}/edit");

    $errors = test()->actingAs($admin, 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.errors');

    expect($errors)->toBeArray()->toHaveKey('name.en')
        ->and($errors['name.en'])->toBeString()->not->toBe('');
});

it('preserves a block whose kind is no longer registered instead of crashing on save', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'Hi']],
        ['id' => 'ghost1', 'kind' => 'Ghost', 'active' => true, 'sort' => 1, 'data' => ['legacy' => 'value']],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'name' => ['en' => 'Renamed via save'],
            'block_blk1_heading' => 'Hi', 'block_blk1_active' => true, 'block_blk1_sort' => 0,
        ],
    )->assertRedirect()->assertSessionHasNoErrors();

    // The save must have actually run — not bounced off validation.
    expect($page->refresh()->getTranslation('name', 'en'))->toBe('Renamed via save');

    $ghost = collect($page->content)->firstWhere('id', 'ghost1');
    expect($ghost)->not->toBeNull()
        ->and($ghost['kind'])->toBe('Ghost')
        ->and($ghost['data'])->toBe(['legacy' => 'value']);
});

it('round-trips the rich-text body through save as a per-locale map', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $lexical = ['root' => ['type' => 'root', 'children' => []]];

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'body' => ['en' => $lexical]],
    )->assertRedirect();

    expect($page->refresh()->getTranslation('body', 'en'))->toBe($lexical);
});

it('keeps the sidebar cards for status/type/parent/sort_order out of the General tab', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $grid = findNodeByKind($form, 'grid');

    expect($grid)->not->toBeNull();

    $sidebar = $grid['options']['children'][1] ?? null;
    expect($sidebar)->not->toBeNull();

    $sidebarFieldNames = [];
    foreach ((array) ($sidebar['options']['children'] ?? []) as $section) {
        foreach ((array) ($section['options']['children'] ?? []) as $field) {
            $sidebarFieldNames[] = $field['name'] ?? null;
        }
    }

    expect($sidebarFieldNames)->toContain('status', 'type', 'parent_id', 'sort_order', 'is_indexable');
});

it('puts status and is_indexable together in the sidebar Publication card', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $grid = findNodeByKind($form, 'grid');
    $sidebar = $grid['options']['children'][1];
    $publicationCard = $sidebar['options']['children'][0];

    $names = array_column($publicationCard['options']['children'], 'name');
    expect($names)->toBe(['status', 'is_indexable', 'published_at', 'unpublish_at']);
});

it('renders a hidden lock_version field before the status bar, then the status bar with a status badge', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $lockVersionField = $form['options']['children'][0];
    $statusBar = $form['options']['children'][1];

    expect($lockVersionField['kind'])->toBe('number')
        ->and($lockVersionField['name'])->toBe('lock_version')
        ->and($lockVersionField['meta']['hidden'] ?? null)->toBeTrue();

    expect($statusBar['kind'])->toBe('flex')
        ->and($statusBar['options']['variant'])->toBe('card');

    $badge = findNodeByKind($statusBar, 'displayValue');
    expect($badge)->not->toBeNull()
        ->and($badge['options']['kind'])->toBe('badge');
});

it('badges a scheduled page as Scheduled and shows a note with the publish datetime', function (): void {
    $publishedAt = now()->addDay();
    $page = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'published_at' => $publishedAt,
    ]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $statusBar = $form['options']['children'][1];

    $badge = findNodeByKind($statusBar, 'displayValue');
    expect($badge['options']['value'])->toBe('Scheduled');

    $texts = array_filter($statusBar['options']['children'], static fn (array $c): bool => ($c['kind'] ?? null) === 'displayText');
    $noteContent = array_column(array_values($texts), 'options');
    $hasScheduleNote = false;
    foreach ($noteContent as $opts) {
        if (str_contains((string) ($opts['content'] ?? ''), 'Scheduled for')) {
            $hasScheduleNote = true;
        }
    }

    expect($hasScheduleNote)->toBeTrue();
});

it('badges an expired page as Expired', function (): void {
    $page = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'unpublish_at' => now()->subDay(),
    ]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    $statusBar = $form['options']['children'][1];

    $badge = findNodeByKind($statusBar, 'displayValue');
    expect($badge['options']['value'])->toBe('Expired');
});

it('includes breadcrumbs linking back to the pages list', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About Us']]);

    $breadcrumbs = pageEditStructure($page->id)['props']['breadcrumbs'];

    expect($breadcrumbs)->toHaveCount(3)
        ->and($breadcrumbs[1]['label'])->toBe('Pages')
        ->and($breadcrumbs[1]['url'] ?? null)->toEndWith('/admin/pages')
        ->and($breadcrumbs[2]['label'])->toBe('About Us');
});

it('saves general and SEO fields including the seo json column', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            'name' => ['en' => 'About Updated'],
            'title' => ['en' => ''],
            'slug' => ['en' => 'about-updated'],
            'heading' => ['en' => ''],
            'summary' => ['en' => ''],
            'type' => 'page',
            'parent_id' => 'none',
            'status' => PageStatus::Published->value,
            'sort_order' => 5,
            'seo_title' => ['en' => 'Meta title'],
            'seo_description' => ['en' => 'Meta description'],
            'seo_focus_keyword' => ['en' => 'widgets'],
            'og_title' => ['en' => 'OG title'],
            'og_description' => ['en' => 'OG description'],
            'og_image' => '42',
            'seo_canonical' => ['en' => 'https://example.com/canonical'],
            'is_indexable' => true,
        ],
    );

    $response->assertRedirect();
    $page->refresh();

    expect($page->getTranslation('name', 'en'))->toBe('About Updated')
        ->and($page->sort_order)->toBe(5)
        ->and($page->seo)->toBe([
            'title' => ['en' => 'Meta title'],
            'description' => ['en' => 'Meta description'],
            'focus_keyword' => ['en' => 'widgets'],
            'og_title' => ['en' => 'OG title'],
            'og_description' => ['en' => 'OG description'],
            'og_image' => '42',
            'canonical' => ['en' => 'https://example.com/canonical'],
        ]);
});

it('placeholders seo_title/seo_description/og_title/og_description with the computed final value for the default locale', function (): void {
    app(Settings::class)->set('seo.title_suffix', '— Acme');
    $page = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'summary' => ['en' => 'A short summary.'],
    ]);

    $tabs = editFormTabs($page->id);
    $seoTitle = findFieldByName($tabs[1]['body'], 'seo_title');
    $seoDescription = findFieldByName($tabs[1]['body'], 'seo_description');

    expect($seoTitle['options']['placeholder'] ?? null)->toBe('About Page — Acme')
        ->and($seoDescription['options']['placeholder'] ?? null)->toBe('A short summary.');
});

it('rejects an invalid seo_canonical URL for the default locale', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'seo_canonical' => ['en' => 'not-a-url']],
    )->assertSessionHasErrors('seo_canonical.en');
});

it('rejects an invalid seo_canonical URL for a non-default locale', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'seo_canonical' => ['en' => '', 'uk' => 'not-a-url']],
    )->assertSessionHasErrors('seo_canonical.uk');
});

it('accepts a valid seo_canonical URL for a non-default locale', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'seo_canonical' => ['en' => '', 'uk' => 'https://example.com/uk-canonical']],
    )->assertRedirect()->assertSessionHasNoErrors();

    expect($page->refresh()->seo['canonical'])->toBe(['en' => '', 'uk' => 'https://example.com/uk-canonical']);
});

it('rejects a seo_canonical URL with a non-http(s) scheme', function (): void {
    // The bare 'url' rule accepts any scheme a browser tolerates; ftp:// is a
    // scheme that parses as a valid URL but is never a legitimate canonical.
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'seo_canonical' => ['en' => 'ftp://example.com/file']],
    )->assertSessionHasErrors('seo_canonical.en');
});

it('rejects a seo_canonical URL with a javascript scheme', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...baseEditPayload(), 'seo_canonical' => ['en' => 'javascript:alert(1)']],
    )->assertSessionHasErrors('seo_canonical.en');
});

it('round-trips the new SEO keys back into the edit form via loadRow', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $session = test()->actingAs(makePageEditAdmin(), 'web');

    $session->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'seo_focus_keyword' => ['en' => 'widgets'],
            'og_title' => ['en' => 'OG title'],
            'og_description' => ['en' => 'OG description'],
            'og_image' => '42',
        ],
    )->assertRedirect();

    $pageEditor = $session->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.data.pageEditor');

    expect($pageEditor['seo_focus_keyword'])->toBe(['en' => 'widgets'])
        ->and($pageEditor['og_title'])->toBe(['en' => 'OG title'])
        ->and($pageEditor['og_description'])->toBe(['en' => 'OG description'])
        ->and($pageEditor['og_image'])->toBe('42');
});

it('round-trips two blocks through save: data, active, and order are preserved', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'One', 'body' => 'First']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'Two', 'body' => 'Second']],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One Updated',
            'block_blk1_body' => 'First Updated',
            'block_blk1_active' => true,
            'block_blk1_sort' => 0,
            'block_blk2_heading' => 'Two',
            'block_blk2_body' => 'Second',
            'block_blk2_active' => false,
            'block_blk2_sort' => 1,
        ],
    )->assertRedirect();

    $content = $page->refresh()->content;

    expect($content)->toHaveCount(2)
        ->and($content[0]['id'])->toBe('blk1')
        ->and($content[0]['data'])->toBe(['heading' => 'One Updated', 'body' => 'First Updated'])
        ->and($content[0]['active'])->toBeTrue()
        ->and($content[0]['sort'])->toBe(0)
        ->and($content[1]['id'])->toBe('blk2')
        ->and($content[1]['data'])->toBe(['heading' => 'Two', 'body' => 'Second'])
        ->and($content[1]['active'])->toBeFalse()
        ->and($content[1]['sort'])->toBe(1);
});

it('reorders the envelope when block_{id}_sort is changed on save', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'One']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'sort' => 1, 'data' => ['heading' => 'Two']],
    ]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One',
            'block_blk1_active' => true,
            'block_blk1_sort' => 5,
            'block_blk2_heading' => 'Two',
            'block_blk2_active' => true,
            'block_blk2_sort' => 1,
        ],
    )->assertRedirect();

    $content = $page->refresh()->content;

    expect(array_column($content, 'id'))->toBe(['blk2', 'blk1'])
        ->and($content[0]['sort'])->toBe(1)
        ->and($content[1]['sort'])->toBe(5);
});

it('reads a legacy envelope without sort in its stored order, then assigns sort on save', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'One']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Two']],
    ]);

    expect($page->content[0])->not->toHaveKey('sort');

    $admin = makePageEditAdmin();
    $session = test()->actingAs($admin, 'web');

    $tabsJson = $session->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])->assertOk()->json();
    $form = findNodeByKind($tabsJson['props']['structure'], 'form');
    $tabsNode = findNodeByKind($form, 'tabs');
    $tabs = $tabsNode['options']['tabs'];
    $firstNames = [];
    collectFieldNames($tabs[2]['body'], $firstNames);
    expect($firstNames)->toContain('block_blk1_sort');

    $session->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One',
            'block_blk1_active' => true,
            'block_blk1_sort' => 0,
            'block_blk2_heading' => 'Two',
            'block_blk2_active' => true,
            'block_blk2_sort' => 1,
        ],
    )->assertRedirect();

    $content = $page->refresh()->content;

    expect(array_column($content, 'id'))->toBe(['blk1', 'blk2'])
        ->and($content[0]['sort'])->toBe(0)
        ->and($content[1]['sort'])->toBe(1);
});

it('adds a new block via pageBlockAdd_{kind} as inactive so it does not leak into public render', function (): void {
    $page = pageWithBlocks();

    $response = test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockAdd_Simple",
        ['payload' => ['form' => [...baseEditPayload(), 'name' => ['en' => 'Renamed']]]],
    );

    $response->assertOk();
    $page->refresh();

    expect($page->content)->toHaveCount(1)
        ->and($page->content[0]['kind'])->toBe('Simple')
        ->and($page->content[0]['active'])->toBeFalse()
        ->and($page->content[0]['sort'])->toBe(0)
        ->and($page->getTranslation('name', 'en'))->toBe('Renamed');

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect', 'notify');
});

it('appends a new block after the highest existing sort', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 3, 'data' => ['heading' => 'One']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'sort' => 7, 'data' => ['heading' => 'Two']],
    ]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockAdd_Simple",
        ['payload' => ['form' => [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One',
            'block_blk1_active' => true,
            'block_blk1_sort' => 3,
            'block_blk2_heading' => 'Two',
            'block_blk2_active' => true,
            'block_blk2_sort' => 7,
        ]]],
    );

    $response->assertOk();
    $content = $page->refresh()->content;

    expect($content)->toHaveCount(3)
        ->and($content[2]['sort'])->toBe(8)
        ->and($content[2]['active'])->toBeFalse();
});

it('removes a block via pageBlockRemove_{id} and keeps the other blocks intact', function (): void {
    $page = pageWithBlocks([
        ['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'One']],
        ['id' => 'blk2', 'kind' => 'Simple', 'active' => true, 'data' => ['heading' => 'Two']],
    ]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/actions/pageBlockRemove_blk1",
        ['payload' => ['form' => [
            ...baseEditPayload(),
            'block_blk1_heading' => 'One',
            'block_blk1_active' => true,
            'block_blk2_heading' => 'Two',
            'block_blk2_active' => true,
        ]]],
    );

    $response->assertOk();
    $page->refresh();

    expect($page->content)->toHaveCount(1)
        ->and($page->content[0]['id'])->toBe('blk2');

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect');
});

it('keeps header actions empty now that view-on-site lives in the status bar', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $headerActions = test()->actingAs(makePageEditAdmin(), 'web')
        ->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.headerActions') ?? [];

    expect($headerActions)->toBe([]);
});

it('places view-on-site and a top save action in the status bar', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($page->id)['props']['structure'], 'form');
    // children[0] is the hidden lock_version field (see the "renders a
    // hidden lock_version field..." test) — the status bar is the next one.
    $statusBar = $form['options']['children'][1];
    $names = array_column($statusBar['options']['children'], 'name');

    expect($names)->toContain('pageEditView', 'saveTop');
});

it('hides delete from the form footer for system pages', function (): void {
    $system = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Home'], 'is_system' => true]);

    $form = findNodeByKind(pageEditStructure($system->id)['props']['structure'], 'form');
    $footerActions = footerActionNames($form);

    expect($footerActions)->toBe(['cancel', 'save']);
});

it('omits the slug field from the General tab for the root system page', function (): void {
    $system = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Home'], 'is_system' => true]);

    $tabs = editFormTabs($system->id);
    $generalFieldNames = array_column($tabs[0]['body']['options']['children'], 'name');

    expect($generalFieldNames)->not->toContain('slug');
});

it('saves the system page with no slug key in the payload and keeps its slug/path empty', function (): void {
    $system = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Home'], 'is_system' => true]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$system->id}/edit/forms/pageEditor",
        [
            'name' => ['en' => 'Home Updated'],
            'title' => ['en' => ''],
            'heading' => ['en' => ''],
            'summary' => ['en' => ''],
            'type' => 'page',
            'parent_id' => 'none',
            'status' => PageStatus::Published->value,
            'sort_order' => 0,
            'seo_title' => ['en' => ''],
            'seo_description' => ['en' => ''],
            'seo_focus_keyword' => ['en' => ''],
            'og_title' => ['en' => ''],
            'og_description' => ['en' => ''],
            'og_image' => '',
            'is_indexable' => true,
        ],
    )->assertRedirect()->assertSessionHasNoErrors();

    $system->refresh();
    $slug = PageSlug::query()->where('page_id', $system->id)->where('locale', 'en')->first();

    expect($system->getTranslation('name', 'en'))->toBe('Home Updated')
        ->and($slug)->not->toBeNull()
        ->and($slug->slug)->toBe('')
        ->and($slug->path)->toBe('')
        ->and(Redirect::query()->where('locale', 'en')->exists())->toBeFalse();
});

it('ignores a slug override submitted for the system page and keeps its slug/path empty', function (): void {
    $system = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Home'], 'is_system' => true]);

    test()->actingAs(makePageEditAdmin(), 'web')->post(
        "/admin/pages/{$system->id}/edit/forms/pageEditor",
        [
            'name' => ['en' => 'Home Updated'],
            'title' => ['en' => ''],
            'slug' => ['en' => 'home'],
            'heading' => ['en' => ''],
            'summary' => ['en' => ''],
            'type' => 'page',
            'parent_id' => 'none',
            'status' => PageStatus::Published->value,
            'sort_order' => 0,
            'seo_title' => ['en' => ''],
            'seo_description' => ['en' => ''],
            'seo_focus_keyword' => ['en' => ''],
            'og_title' => ['en' => ''],
            'og_description' => ['en' => ''],
            'og_image' => '',
            'is_indexable' => true,
        ],
    )->assertRedirect()->assertSessionHasNoErrors();

    $system->refresh();
    $slug = PageSlug::query()->where('page_id', $system->id)->where('locale', 'en')->first();

    expect($slug)->not->toBeNull()
        ->and($slug->slug)->toBe('')
        ->and($slug->path)->toBe('')
        ->and(Redirect::query()->where('locale', 'en')->exists())->toBeFalse();
});

it('shows the preview-link action in the status bar for a draft page', function (): void {
    $draft = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Draft page']]);

    $form = findNodeByKind(pageEditStructure($draft->id)['props']['structure'], 'form');
    // children[0] is the hidden lock_version field — see the "renders a
    // hidden lock_version field..." test.
    $statusBar = $form['options']['children'][1];
    $names = array_column($statusBar['options']['children'], 'name');

    expect($names)->toContain('pagePreviewLink');
});

/** @return array<string, mixed> */
function previewLinkNode(int $pageId): array
{
    $form = findNodeByKind(pageEditStructure($pageId)['props']['structure'], 'form');
    $statusBar = $form['options']['children'][1];
    foreach ($statusBar['options']['children'] as $child) {
        if (($child['name'] ?? null) === 'pagePreviewLink') {
            return $child;
        }
    }

    throw new RuntimeException('pagePreviewLink action not found in status bar.');
}

it('offers Copy before Close in the preview-link modal footer', function (): void {
    $draft = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Draft page']]);

    $footer = previewLinkNode($draft->id)['options']['spec']['body']['options']['children'][2];
    // The spacer serializes without a name, so array_column skips it.
    $names = array_column($footer['options']['children'], 'name');
    $labelByName = array_column($footer['options']['children'], 'options', 'name');

    expect($names)->toBe(['pagePreviewLinkCopy', 'pagePreviewLinkClose'])
        ->and($labelByName['pagePreviewLinkCopy']['label'])->toBe('Copy')
        ->and($labelByName['pagePreviewLinkClose']['label'])->toBe('Close');
});

it('returns a copyToClipboard effect with the signed preview URL from the Copy action', function (): void {
    // PreviewLink::for() builds a signed URL to the frontend page route,
    // which the admin test bootstrap does not register.
    app(Cms::class)->frontendRoutes();
    $draft = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Draft page']]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')
        ->postJson("/admin/pages/{$draft->id}/edit/actions/pagePreviewLinkCopy", ['payload' => []])
        ->assertOk();

    $effects = $response->json('effects');
    // The default locale is unprefixed, so the path is /draft-page.
    expect($effects)->toHaveCount(1)
        ->and($effects[0]['kind'])->toBe('copyToClipboard')
        ->and($effects[0]['text'])->toContain('/draft-page')
        ->and($effects[0]['text'])->toContain('signature=');
});

it('omits the preview-link action from the status bar for a live published page', function (): void {
    $published = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Live page']]);

    $form = findNodeByKind(pageEditStructure($published->id)['props']['structure'], 'form');
    $statusBar = $form['options']['children'][1];
    $names = array_column($statusBar['options']['children'], 'name');

    expect($names)->not->toContain('pagePreviewLink');
});

it('shows the preview-link action for a scheduled page (published_at in the future)', function (): void {
    $scheduled = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Scheduled page'],
        'published_at' => now()->addDay(),
    ]);

    $form = findNodeByKind(pageEditStructure($scheduled->id)['props']['structure'], 'form');
    $statusBar = $form['options']['children'][1];
    $names = array_column($statusBar['options']['children'], 'name');

    expect($names)->toContain('pagePreviewLink');
});

it('shows the preview-link action for an expired page (unpublish_at in the past)', function (): void {
    $expired = Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Expired page'],
        'unpublish_at' => now()->subDay(),
    ]);

    $form = findNodeByKind(pageEditStructure($expired->id)['props']['structure'], 'form');
    $statusBar = $form['options']['children'][1];
    $names = array_column($statusBar['options']['children'], 'name');

    expect($names)->toContain('pagePreviewLink');
});

it('keeps delete in the form footer for regular pages', function (): void {
    $regular = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $form = findNodeByKind(pageEditStructure($regular->id)['props']['structure'], 'form');
    $footerActions = footerActionNames($form);

    expect($footerActions)->toBe(['pageEditDelete', 'cancel', 'save']);
});

it('deletes a regular page and redirects to the list', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $response = test()->actingAs(makePageEditAdmin(), 'web')
        ->postJson("/admin/pages/{$page->id}/edit/actions/pageEditDelete", ['payload' => []]);

    $response->assertOk();
    expect(Page::query()->find($page->id))->toBeNull();

    $effects = collect($response->json('effects'))->pluck('kind');
    expect($effects)->toContain('redirect');
});

it('hides and unregisters the delete action for a page that has children, instead of it 500ing', function (): void {
    $category = Page::factory()->category()->create();
    Page::factory()->article()->create(['parent_id' => $category->id]);

    // The DSL rebuilds the page fresh per request (ResolvedPage::fromRequest);
    // once canDelete() omits deleteAction($s), pageEditDelete is never added
    // to collectedActions(), so the route resolves to a clean 404 instead of
    // reaching PageObserver's DomainException at all.
    $response = test()->actingAs(makePageEditAdmin(), 'web')
        ->postJson("/admin/pages/{$category->id}/edit/actions/pageEditDelete", ['payload' => []]);

    $response->assertNotFound();
    expect(Page::query()->find($category->id))->not->toBeNull();
});

it('hides delete from the form footer for a page that has children', function (): void {
    $category = Page::factory()->category()->create();
    Page::factory()->article()->create(['parent_id' => $category->id]);

    $form = findNodeByKind(pageEditStructure($category->id)['props']['structure'], 'form');
    $footerActions = footerActionNames($form);

    expect($footerActions)->toBe(['cancel', 'save']);
});

/** @return array<string, mixed> Minimal valid general/SEO payload for the pageEditor form. */
function baseEditPayload(): array
{
    return [
        'name' => ['en' => 'About Us'],
        'title' => ['en' => ''],
        'slug' => ['en' => 'about-us'],
        'heading' => ['en' => ''],
        'summary' => ['en' => ''],
        'type' => 'page',
        'parent_id' => 'none',
        'status' => PageStatus::Published->value,
        'sort_order' => 0,
        'seo_title' => ['en' => ''],
        'seo_description' => ['en' => ''],
        'seo_focus_keyword' => ['en' => ''],
        'og_title' => ['en' => ''],
        'og_description' => ['en' => ''],
        'og_image' => '',
        'is_indexable' => true,
    ];
}

/** @return list<array<string, mixed>> */
function editFormTabs(int $pageId): array
{
    $form = findNodeByKind(pageEditStructure($pageId)['props']['structure'], 'form');
    $tabsNode = findNodeByKind($form, 'tabs');
    expect($tabsNode)->not->toBeNull();

    return $tabsNode['options']['tabs'];
}

/** @param array<string, mixed> $node @return array<string, mixed>|null */
function findNodeByKind(array $node, string $kind): ?array
{
    if (($node['kind'] ?? null) === $kind) {
        return $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (! is_array($child)) {
            continue;
        }
        $found = findNodeByKind($child, $kind);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * Action names inside the form footer's flex node (delete/spacer/save) —
 * the LAST flex node in the tree, to avoid matching the status bar's flex.
 *
 * @param  array<string, mixed>|null  $form
 * @return list<string>
 */
function footerActionNames(?array $form): array
{
    if ($form === null) {
        return [];
    }

    $flexes = [];
    collectNodesByKind($form, 'flex', $flexes);
    expect($flexes)->not->toBeEmpty();

    $footer = $flexes[array_key_last($flexes)];
    $names = [];
    foreach ((array) ($footer['options']['children'] ?? []) as $child) {
        if (is_array($child) && ($child['kind'] ?? null) === 'action') {
            $names[] = $child['name'];
        }
    }

    return $names;
}

/**
 * @param  array<string, mixed>  $node
 * @param  list<array<string, mixed>>  $out
 */
function collectNodesByKind(array $node, string $kind, array &$out): void
{
    if (($node['kind'] ?? null) === $kind) {
        $out[] = $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            collectNodesByKind($child, $kind, $out);
        }
    }
    foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
        if (is_array($tab) && isset($tab['body']) && is_array($tab['body'])) {
            collectNodesByKind($tab['body'], $kind, $out);
        }
    }
    // A liveRegion node (SerpPreview/OgPreview) carries its render output
    // under options.initial rather than options.children — the wire shape
    // LiveRegionBuilder::toNode() produces for the page's first paint.
    foreach ((array) ($node['options']['initial'] ?? []) as $initial) {
        if (is_array($initial)) {
            collectNodesByKind($initial, $kind, $out);
        }
    }
}

/**
 * The first field with this `name` at any depth. Fields sit inside layout
 * wrappers (the SEO tab lays them out in a grid beside the checklist), so a
 * firstWhere() over the top-level children finds nothing.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>|null
 */
function findFieldByName(array $node, string $name): ?array
{
    if (($node['name'] ?? null) === $name) {
        return $node;
    }

    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child) && ($found = findFieldByName($child, $name)) !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * Recursively collects every field `name` under a node, regardless of
 * nesting depth — the active/sort pair lives inside a nested flex row, so a
 * flat array_column() over the top-level children misses them.
 *
 * @param  array<string, mixed>  $node
 * @param  list<string>  $names
 */
function collectFieldNames(array $node, array &$names): void
{
    if (isset($node['name']) && is_string($node['name'])) {
        $names[] = $node['name'];
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            collectFieldNames($child, $names);
        }
    }
}
