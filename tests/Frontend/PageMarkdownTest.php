<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\Markdown\MarkdownContext;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Types\BasePageType;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

// 404s and HTML answers are asked as JSON / Inertia requests: a full HTML
// render needs a built Vite manifest CI does not have.

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Settings::class)->set('site.name', 'Acme');
});

function markdownOn(): void
{
    app(Settings::class)->set('seo.markdown.enabled', true);
    app(Cms::class)->frontendRoutes();
}

/** @param  array<string, mixed>  $attributes */
function mdPage(array $attributes): Page
{
    return Page::factory()->create(['status' => PageStatus::Published, 'title' => $attributes['name'], ...$attributes]);
}

/** @param  list<class-string<Block>>  $blocks */
function mdBlocks(array $blocks): void
{
    config(['tbtop-cms.blocks.types' => [...(array) config('tbtop-cms.blocks.types', []), ...$blocks]]);
    app()->forgetInstance(BlockRegistry::class);
}

/** A block with no toMarkdown(): the fallback reads its translatable fields. */
final class MdNotesBlock extends Block
{
    public function kind(): string
    {
        return 'MdNotes';
    }

    public function form(S $s): array
    {
        return [
            $s->text('title')->translatable(),
            $s->text('code'),
            $s->richtext('body')->translatable(),
            $s->repeater('rows')->fields([$s->text('label')->translatable()]),
        ];
    }
}

final class MdHiddenBlock extends Block
{
    public function kind(): string
    {
        return 'MdHidden';
    }

    public function form(S $s): array
    {
        return [$s->text('title')->translatable()];
    }

    #[Override]
    public function toMarkdown(array $props, MarkdownContext $ctx): string
    {
        return '';
    }
}

final class MdThrowingBlock extends Block
{
    public function kind(): string
    {
        return 'MdThrowing';
    }

    public function form(S $s): array
    {
        return [$s->text('title')->translatable()];
    }

    #[Override]
    public function toMarkdown(array $props, MarkdownContext $ctx): string
    {
        throw new RuntimeException('boom');
    }
}

/** @param  array<string, mixed>  $data */
function mdBlock(string $kind, array $data, string $id = 'b1'): array
{
    return ['id' => $id, 'kind' => $kind, 'active' => true, 'data' => $data];
}

it('is not served until an editor turns it on', function (): void {
    app(Cms::class)->frontendRoutes();
    mdPage(['name' => ['en' => 'About']]);

    $this->getJson('/about.md')->assertNotFound();
});

it('is not served while indexing is off', function (): void {
    app(Settings::class)->set('seo.indexing_enabled', false);
    markdownOn();
    mdPage(['name' => ['en' => 'About']]);

    $this->getJson('/about.md')->assertNotFound();
});

it('is never served when the host config turns it off', function (): void {
    config(['tbtop-cms.seo.markdown.enabled' => false]);
    markdownOn();
    mdPage(['name' => ['en' => 'About']]);

    $this->getJson('/about.md')->assertNotFound();
});

it('serves the page as markdown at its path plus .md', function (): void {
    markdownOn();
    mdPage([
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About us'],
        'heading' => ['en' => 'We fix bikes'],
        'summary' => ['en' => "Since 2010.\n\n# Not a heading *really*"],
        'body' => ['en' => '<p>Read the <strong>price list</strong> at <a href="/prices">prices</a>.</p><ul><li>Wheels</li><li>Brakes</li></ul>'],
        'seo' => ['description' => ['en' => 'Who we are']],
    ]);

    $response = $this->get('/about.md')->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
        ->assertHeader('Link', '<' . url('/about') . '>; rel="canonical"')
        ->assertHeaderMissing('X-Robots-Tag');

    expect($response->getVary())->toContain('Accept');

    $body = $response->getContent();

    expect($body)->toStartWith("---\ntitle: ")
        ->toContain("description: \"Who we are\"\nurl: \"" . url('/about') . "\"\nlang: \"en\"\n---\n\n# We fix bikes\n\n")
        ->toContain("Since 2010.\n\n\\# Not a heading \\*really\\*")
        ->toContain('Read the **price list** at [prices](' . url('/prices') . ').')
        ->toContain("- Wheels\n- Brakes")
        ->toContain("```json\n[\n");
});

it('serves markdown to a request that prefers it', function (): void {
    markdownOn();
    mdPage(['name' => ['en' => 'About'], 'heading' => ['en' => 'About us']]);

    $this->get('/about', ['Accept' => 'text/markdown, text/html;q=0.9'])->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
        ->assertSee('# About us', false);
});

it('serves the home page at /index.md', function (): void {
    markdownOn();
    mdPage(['name' => ['en' => 'Home'], 'heading' => ['en' => 'Welcome'], 'is_system' => true]);

    $this->get('/index.md')->assertOk()->assertSee('# Welcome', false);
});

it('serves a page in another locale under its prefix', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    markdownOn();
    $page = mdPage(['name' => ['en' => 'About', 'uk' => 'Pro nas'], 'heading' => ['en' => 'About', 'uk' => 'Pro nas']]);

    $body = $this->get('/uk/' . $page->pathFor('uk') . '.md')->assertOk()->getContent();

    expect($body)->toContain('lang: "uk"')
        ->toContain('# Pro nas')
        ->toContain("alternates:\n  \"en\": \"" . url('/about') . '"');
});

it('never serves a draft, not even to an admin', function (): void {
    markdownOn();
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    mdPage(['name' => ['en' => 'Secret'], 'status' => PageStatus::Draft]);

    $this->actingAs($user)->getJson('/secret.md')->assertNotFound();
});

it('tags a noindex page in the header', function (): void {
    markdownOn();
    mdPage(['name' => ['en' => 'Hidden'], 'is_indexable' => false]);

    $this->get('/hidden.md')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});

it('sends the content signals the editor chose', function (): void {
    app(Settings::class)->set('seo.content_signals.enabled', true);
    markdownOn();
    mdPage(['name' => ['en' => 'About']]);

    $this->get('/about.md')->assertHeader('Content-Signal', 'search=yes, ai-input=yes, ai-train=no');
});

it('points the HTML answer at its markdown twin and llms.txt', function (): void {
    app(Settings::class)->set('seo.llms_txt.enabled', true);
    markdownOn();
    mdPage(['name' => ['en' => 'About']]);

    $response = $this->get('/about', ['X-Inertia' => 'true'])->assertOk();

    expect($response->headers->get('Link'))
        ->toBe('<' . url('/about.md') . '>; rel="alternate"; type="text/markdown", <' . url('llms.txt') . '>; rel="describedby"; type="text/plain"')
        ->and($response->getVary())->toContain('Accept');
});

it('leaves the HTML answer alone while markdown is off', function (): void {
    app(Cms::class)->frontendRoutes();
    mdPage(['name' => ['en' => 'About']]);

    $response = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->assertHeaderMissing('Link');

    expect($response->getVary())->not->toContain('Accept');
});

it('renders the built-in blocks and a block with its own markdown', function (): void {
    markdownOn();
    mdPage(['name' => ['en' => 'About'], 'content' => [
        mdBlock('Hero', ['title' => ['en' => 'Fast repairs'], 'subtitle' => ['en' => 'Same day.'], 'ctaLabel' => ['en' => 'Book'], 'ctaHref' => '/book'], 'h'),
        mdBlock('faq', ['heading' => ['en' => 'FAQ'], 'items' => [['question' => ['en' => 'Do you open on Sunday?'], 'answer' => ['en' => 'No.']]]], 'f'),
        mdBlock('ContactForm', ['heading' => ['en' => 'Write to us']], 'c'),
    ]]);

    $body = $this->get('/about.md')->getContent();

    expect($body)->toContain("## Fast repairs\n\nSame day.\n\n[Book](" . url('/book') . ')')
        ->toContain("## FAQ\n\n### Do you open on Sunday?\n\nNo.")
        ->toContain("## Write to us\n\n" . __('tbtop-cms::llms.markdown.form'))
        ->toContain('"@type": "FAQPage"')
        ->and(strpos($body, '## Fast repairs'))->toBeLessThan(strpos($body, '## FAQ'));
});

it('falls back to the text of a block without its own markdown', function (): void {
    mdBlocks([MdNotesBlock::class, MdHiddenBlock::class, MdThrowingBlock::class]);
    markdownOn();
    mdPage(['name' => ['en' => 'About'], 'content' => [
        mdBlock('MdNotes', [
            'title' => ['en' => 'Notes [beta]'],
            'code' => 'SKU-1',
            'body' => ['en' => '<p>One <em>two</em></p>'],
            'rows' => [['label' => ['en' => 'First']], ['label' => ['en' => 'Second']]],
        ], 'n'),
        mdBlock('MdHidden', ['title' => ['en' => 'Invisible']], 'x'),
        mdBlock('MdThrowing', ['title' => ['en' => 'Broken']], 't'),
    ]]);

    $body = $this->get('/about.md')->assertOk()->getContent();

    expect($body)->toContain("Notes \\[beta\\]\n\nOne _two_\n\nFirst\n\nSecond")
        ->not->toContain('SKU-1')
        ->not->toContain('Invisible')
        ->not->toContain('Broken');
});

it('lists the live indexable children of a category', function (): void {
    markdownOn();
    $blog = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Blog'], 'title' => ['en' => 'Blog']]);
    $make = fn (array $attributes): Page => Page::factory()->article()->create(['status' => PageStatus::Published, 'parent_id' => $blog->id, 'title' => $attributes['name'], ...$attributes]);
    $old = $make(['name' => ['en' => 'Old news'], 'published_at' => now()->subDays(2), 'seo' => ['description' => ['en' => 'From last week']]]);
    $new = $make(['name' => ['en' => 'New news'], 'published_at' => now()->subDay()]);
    $make(['name' => ['en' => 'Draft news'], 'status' => PageStatus::Draft]);
    $make(['name' => ['en' => 'Hidden news'], 'is_indexable' => false]);

    $body = $this->get('/' . $blog->pathFor('en') . '.md')->assertOk()->getContent();

    expect($body)->toContain("## In this section\n\n- [New news](" . url('/' . $new->pathFor('en') . '.md') . ")\n- [Old news](" . url('/' . $old->pathFor('en') . '.md') . '): From last week')
        ->not->toContain('Draft news')
        ->not->toContain('Hidden news');
});

it('ends with the notes for AI and the contact details', function (): void {
    app(Settings::class)->set('seo.llms_txt.intro', ['en' => 'We ship daily.']);
    app(Settings::class)->set('seo.identity.type', 'local_business');
    app(Settings::class)->set('seo.identity.opening_hours', [['days' => ['Monday'], 'opens' => '09:00', 'closes' => '18:00']]);
    markdownOn();
    mdPage(['name' => ['en' => 'About']]);

    $body = $this->get('/about.md')->getContent();

    expect($body)->toContain("## About Acme\n\nWe ship daily.\n\n- Opening hours: Monday 09:00–18:00")
        ->and(strpos($body, '## About Acme'))->toBeLessThan(strpos($body, '```json'));
});

it('links llms.txt entries to the markdown versions', function (): void {
    app(Settings::class)->set('seo.llms_txt.enabled', true);
    markdownOn();
    mdPage(['name' => ['en' => 'About']]);

    expect($this->get('/llms.txt')->getContent())->toContain('- [About](' . url('/about.md') . ')');
});

it('still 404s an unknown .md path', function (): void {
    markdownOn();

    $this->getJson('/missing.md')->assertNotFound();
});

/** A page type whose markdown part throws. */
final class MdBrokenType extends BasePageType
{
    public function key(): string
    {
        return 'md_broken';
    }

    #[Override]
    public function toMarkdown(Page $page, MarkdownContext $ctx): string
    {
        throw new RuntimeException('boom');
    }
}

/** A page type that links an unsafe and a safe URL. */
final class MdLinkedType extends BasePageType
{
    public function key(): string
    {
        return 'md_linked';
    }

    #[Override]
    public function toMarkdown(Page $page, MarkdownContext $ctx): string
    {
        return $ctx->link('Bad', 'javascript:alert(1)') . ' ' . $ctx->link('Good', '/ok');
    }
}

it('leaves out a page type part that throws; strict mode rethrows', function (): void {
    PageTypeRegistry::extend(fn (): array => [MdBrokenType::class]);
    markdownOn();
    mdPage(['type' => 'md_broken', 'name' => ['en' => 'Broken']]);

    expect($this->get('/broken.md')->assertOk()->getContent())->toContain('# Broken');

    config(['tbtop-cms.blocks.strict' => true]);
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/broken.md'))->toThrow(RuntimeException::class, 'boom');
});

it('drops the link of an unsafe URL and keeps its text', function (): void {
    PageTypeRegistry::extend(fn (): array => [MdLinkedType::class]);
    markdownOn();
    mdPage(['type' => 'md_linked', 'name' => ['en' => 'Linked']]);

    expect($this->get('/linked.md')->assertOk()->getContent())->toContain('Bad [Good](' . url('/ok') . ')')
        ->not->toContain('javascript:');
});
