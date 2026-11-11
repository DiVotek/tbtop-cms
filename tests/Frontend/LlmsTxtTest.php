<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxtLink;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxtSection;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\SectionType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Settings::class)->set('site.name', 'Acme');
});

function llmsTxtOn(): void
{
    app(Settings::class)->set('seo.llms_txt.enabled', true);
    app(Cms::class)->frontendRoutes();
}

/** @param  array<string, mixed>  $attributes */
function llmsPage(array $attributes): Page
{
    return Page::factory()->create(['status' => PageStatus::Published, 'title' => $attributes['name'], ...$attributes]);
}

/** @param  array<string, mixed>  $attributes */
function llmsCategory(array $attributes): Page
{
    return Page::factory()->category()->create(['status' => PageStatus::Published, 'title' => $attributes['name'], ...$attributes]);
}

/** @param  array<string, mixed>  $attributes */
function llmsArticle(array $attributes): Page
{
    $category = Page::query()->where('type', 'category')->first()
        ?? llmsCategory(['name' => ['en' => 'Blog']]);

    return Page::factory()->article()->create(['status' => PageStatus::Published, 'parent_id' => $category->id, 'title' => $attributes['name'], ...$attributes]);
}

final class LlmsTxtDocsSection implements LlmsTxtSection
{
    public function title(string $locale): string
    {
        return 'Docs';
    }

    public function links(string $locale): array
    {
        return [new LlmsTxtLink('API', 'https://docs.example.com/api', 'REST reference')];
    }
}

final class LlmsTxtRelativeSection implements LlmsTxtSection
{
    public function title(string $locale): string
    {
        return 'Files';
    }

    public function links(string $locale): array
    {
        return [new LlmsTxtLink('Price list', '/files/price list(v2).pdf')];
    }
}

it('is not served until an editor turns it on', function (): void {
    app(Cms::class)->frontendRoutes();
    llmsPage(['name' => ['en' => 'About']]);

    $this->getJson('/llms.txt')->assertNotFound();
});

it('is not served while indexing is off', function (): void {
    app(Settings::class)->set('seo.indexing_enabled', false);
    llmsTxtOn();

    $this->getJson('/llms.txt')->assertNotFound();
});

it('has no route when the host config turns it off', function (): void {
    config(['tbtop-cms.seo.llms_txt.enabled' => false]);
    llmsTxtOn();

    // Falls through to the catch-all page route, which has no such page.
    $this->getJson('/llms.txt')->assertNotFound();
    expect(app('router')->has('tbtop-cms.llms-txt'))->toBeFalse();
});

it('lists the live indexable pages under the site name and summary', function (): void {
    app(Settings::class)->set('seo.llms_txt.summary', ['en' => 'Bike repair in Kyiv.']);
    llmsTxtOn();

    $about = llmsPage(['name' => ['en' => 'About'], 'title' => ['en' => 'About us'], 'seo' => ['description' => ['en' => 'Who we are']]]);
    llmsPage(['name' => ['en' => 'Draft'], 'status' => PageStatus::Draft]);
    llmsPage(['name' => ['en' => 'Hidden'], 'is_indexable' => false]);

    $response = $this->get('/llms.txt')->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    $body = $response->getContent();

    expect($body)->toStartWith("# Acme\n\n> Bike repair in Kyiv.\n\n")
        ->toContain("## Pages\n- [About us](" . url('/' . $about->pathFor('en')) . '): Who we are')
        ->not->toContain('Draft')
        ->not->toContain('Hidden')
        ->toContain("## Optional\n- [Sitemap](" . url('sitemap.xml') . ')');
});

it('falls back to the default meta description for the summary', function (): void {
    app(Settings::class)->set('seo.default_description', ['en' => 'We fix bikes.']);
    llmsTxtOn();

    expect($this->get('/llms.txt')->getContent())->toContain("> We fix bikes.\n");
});

it('adds the editor notes without letting them open headings', function (): void {
    app(Settings::class)->set('seo.llms_txt.intro', ['en' => "## Ordering\nCall us first.\n\n\n\nWe ship daily."]);
    llmsTxtOn();

    expect($this->get('/llms.txt')->getContent())
        ->toContain("Ordering\nCall us first.\n\nWe ship daily.")
        ->not->toContain('## Ordering');
});

it('lists articles newest first, capped per type', function (): void {
    app(Settings::class)->set('seo.llms_txt.per_type', 2);
    llmsTxtOn();

    llmsArticle(['name' => ['en' => 'Oldest'], 'published_at' => now()->subDays(3)]);
    llmsArticle(['name' => ['en' => 'Newest'], 'published_at' => now()->subDay()]);
    llmsArticle(['name' => ['en' => 'Middle'], 'published_at' => now()->subDays(2)]);

    $body = $this->get('/llms.txt')->getContent();

    expect($body)->toContain('## Blog')
        ->and(strpos($body, 'Newest'))->toBeLessThan(strpos($body, 'Middle'))
        ->and($body)->not->toContain('Oldest');
});

it('lists articles under their category in the category order, then the uncategorised ones', function (): void {
    PageTypeRegistry::extend(fn (): array => [SectionType::class]);
    llmsTxtOn();

    $guides = llmsCategory(['name' => ['en' => 'Guides'], 'sort_order' => 2]);
    $services = llmsCategory(['name' => ['en' => 'Services'], 'sort_order' => 1]);
    $brakes = llmsArticle(['name' => ['en' => 'Brake repair'], 'parent_id' => $services->id]);
    $storage = llmsArticle(['name' => ['en' => 'Winter storage'], 'parent_id' => $guides->id]);
    $section = llmsPage(['type' => 'section', 'name' => ['en' => 'News']]);
    $news = llmsArticle(['name' => ['en' => 'We moved'], 'parent_id' => $section->id]);

    expect($this->get('/llms.txt')->getContent())->toContain(
        "## Services\n- [Brake repair](" . url('/' . $brakes->pathFor('en')) . ")\n\n"
        . "## Guides\n- [Winter storage](" . url('/' . $storage->pathFor('en')) . ")\n\n"
        . "## Articles\n- [We moved](" . url('/' . $news->pathFor('en')) . ")\n\n",
    );
});

it('lists the home page first', function (): void {
    llmsTxtOn();

    llmsPage(['name' => ['en' => 'About'], 'sort_order' => 0]);
    llmsPage(['name' => ['en' => 'Home'], 'is_system' => true, 'sort_order' => 9]);

    $body = $this->get('/llms.txt')->getContent();

    expect(strpos($body, '[Home](' . url('/') . ')'))->toBeLessThan(strpos($body, '[About]'));
});

it('lists shallower pages before deeper ones, then by sort order', function (): void {
    llmsTxtOn();

    $parent = llmsCategory(['name' => ['en' => 'Services'], 'sort_order' => 5]);
    llmsCategory(['name' => ['en' => 'Wheels'], 'parent_id' => $parent->id, 'sort_order' => 0]);
    llmsCategory(['name' => ['en' => 'Shop'], 'sort_order' => 9]);

    $body = $this->get('/llms.txt')->getContent();

    expect(strpos($body, '[Services]'))->toBeLessThan(strpos($body, '[Shop]'))
        ->and(strpos($body, '[Shop]'))->toBeLessThan(strpos($body, '[Wheels]'));
});

it('lists only the page types the editor ticked', function (): void {
    app(Settings::class)->set('seo.llms_txt.types', ['article']);
    llmsTxtOn();

    llmsPage(['name' => ['en' => 'About']]);
    llmsArticle(['name' => ['en' => 'News']]);

    expect($this->get('/llms.txt')->getContent())->toContain('[News]')->not->toContain('## Pages');
});

it('strips underline headings from the editor notes', function (): void {
    app(Settings::class)->set('seo.llms_txt.intro', ['en' => "Ordering\n---\nCall us.\n\nPrices\n===="]);
    llmsTxtOn();

    expect($this->get('/llms.txt')->getContent())
        ->toContain("Ordering\n\nCall us.")
        ->not->toContain('---')
        ->not->toContain('====');
});

it('makes host links absolute and keeps them one link', function (): void {
    config(['tbtop-cms.seo.llms_txt.sections' => [LlmsTxtRelativeSection::class]]);
    llmsTxtOn();

    expect($this->get('/llms.txt')->getContent())
        ->toContain('- [Price list](' . url('/files/price%20list%28v2%29.pdf') . ')');
});

it('refreshes the cached file when a setting changes', function (): void {
    llmsTxtOn();
    $this->get('/llms.txt')->assertOk();

    app(Settings::class)->set('seo.llms_txt.summary', ['en' => 'Now with a summary.']);

    expect($this->get('/llms.txt')->getContent())->toContain('> Now with a summary.');
});

it('skips pages untranslated in the locale without spending the limit', function (): void {
    app(Settings::class)->set('seo.llms_txt.per_type', 1);
    llmsTxtOn();

    llmsArticle(['name' => ['en' => 'Untranslated'], 'published_at' => now()->subDay()]);
    $untranslated = Page::query()->where('type', 'article')->sole();
    $untranslated->slugs()->delete();
    llmsArticle(['name' => ['en' => 'Older'], 'published_at' => now()->subDays(2)]);

    expect($this->get('/llms.txt')->getContent())->toContain('[Older]')->not->toContain('Untranslated');
});

it('escapes brackets in link text', function (): void {
    llmsTxtOn();

    llmsPage(['name' => ['en' => 'Prices'], 'title' => ['en' => 'Prices [2026]']]);

    expect($this->get('/llms.txt')->getContent())->toContain('- [Prices \[2026\]](');
});

it('lists the site contacts', function (): void {
    $settings = app(Settings::class);
    $settings->set('seo.identity.type', 'local_business');
    $settings->set('seo.identity.address.street', ['en' => '1 Main St']);
    $settings->set('seo.identity.address.locality', ['en' => 'Kyiv']);
    $settings->set('seo.identity.opening_hours', [['days' => ['Monday', 'Friday'], 'opens' => '09:00', 'closes' => '18:00']]);
    Entry::query()->create(['collection' => 'phones', 'status' => Entry::PUBLISHED, 'sort_order' => 0, 'data' => ['number' => '+380 44 1']]);
    Entry::query()->create(['collection' => 'emails', 'status' => Entry::PUBLISHED, 'sort_order' => 0, 'data' => ['address' => 'hi@acme.test']]);
    Entry::query()->create(['collection' => 'socials', 'status' => Entry::PUBLISHED, 'sort_order' => 0, 'data' => ['label' => 'Telegram', 'href' => 'https://t.me/acme']]);
    llmsTxtOn();

    expect($this->get('/llms.txt')->getContent())->toContain(implode("\n", [
        '- Phone: +380 44 1',
        '- Email: hi@acme.test',
        '- Address: 1 Main St, Kyiv',
        '- Opening hours: Monday, Friday 09:00–18:00',
        '- Telegram: https://t.me/acme',
    ]));
});

it('appends the host sections from config', function (): void {
    config(['tbtop-cms.seo.llms_txt.sections' => [LlmsTxtDocsSection::class]]);
    llmsTxtOn();

    expect($this->get('/llms.txt')->getContent())
        ->toContain("## Docs\n- [API](https://docs.example.com/api): REST reference\n\n## Optional");
});

it('serves another frontend locale under its prefix', function (): void {
    Language::factory()->create(['locale' => 'uk', 'name' => 'Українська']);
    app(Settings::class)->set('seo.llms_txt.summary', ['en' => 'Bike repair.', 'uk' => 'Ремонт велосипедів.']);
    llmsTxtOn();
    $about = llmsPage(['name' => ['en' => 'About', 'uk' => 'Про нас']]);

    $response = $this->get('/uk/llms.txt')->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    expect($response->getContent())->toContain("> Ремонт велосипедів.\n")
        ->toContain('- [Про нас](' . url('/uk/' . $about->pathFor('uk')) . ')')
        ->toContain('](' . url('llms.txt') . '): Цей файл іншою мовою');
});

it('lists the other languages under Optional', function (): void {
    Language::factory()->create(['locale' => 'uk', 'name' => 'Українська']);
    Language::factory()->create(['locale' => 'de', 'name' => 'Deutsch', 'is_frontend_active' => false]);
    llmsTxtOn();

    expect($this->get('/llms.txt')->getContent())->toContain('- [Українська](' . url('uk/llms.txt') . '): This file in another language')
        ->not->toContain('Deutsch');
});

it('redirects the default locale prefix and 404s a locale that is not on the frontend', function (): void {
    Language::factory()->create(['locale' => 'de', 'name' => 'Deutsch', 'is_frontend_active' => false]);
    llmsTxtOn();

    $this->get('/en/llms.txt')->assertRedirect('/llms.txt');
    $this->getJson('/de/llms.txt')->assertNotFound();
});

it('keeps another locale off while llms.txt is off', function (): void {
    Language::factory()->create(['locale' => 'uk', 'name' => 'Українська']);
    app(Cms::class)->frontendRoutes();

    $this->getJson('/uk/llms.txt')->assertNotFound();
});

it('points the HTML answer at its locale llms.txt', function (): void {
    Language::factory()->create(['locale' => 'uk', 'name' => 'Українська']);
    llmsTxtOn();
    $about = llmsPage(['name' => ['en' => 'About', 'uk' => 'Про нас']]);

    $this->get('/uk/' . $about->pathFor('uk'), ['X-Inertia' => 'true'])->assertOk()
        ->assertHeader('Link', '<' . url('uk/llms.txt') . '>; rel="describedby"; type="text/plain"');
    $this->get('/' . $about->pathFor('en'), ['X-Inertia' => 'true'])->assertOk()
        ->assertHeader('Link', '<' . url('llms.txt') . '>; rel="describedby"; type="text/plain"');
});
