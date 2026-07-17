<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Storage::fake('public');
    Language::factory()->default()->create(['locale' => 'en']);

    $settings = app(Settings::class);
    $settings->set('seo.title_suffix', '— Acme');
    $settings->set('seo.default_description', 'Global description');
    $settings->set('seo.default_og_image', (string) media('global.jpg')->getKey());

    app(Cms::class)->frontendRoutes();
});

function media(string $name): Media
{
    return Media::create([
        'name' => $name,
        'disk' => 'public',
        'path' => "media/{$name}",
        'mime' => 'image/jpeg',
        'size' => 123,
    ]);
}

it('lets page SEO overrides win over global defaults', function (): void {
    $pageImage = media('page.jpg');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'is_indexable' => true,
        'seo' => ['description' => 'Page description', 'og_image' => (string) $pageImage->getKey()],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About Page — Acme')
        ->assertJsonPath('props.seo.description', 'Page description')
        ->assertJsonPath('props.seo.ogImage', Storage::disk('public')->url('media/page.jpg'))
        ->assertJsonPath('props.seo.noindex', false);
});

it('falls back to global SEO defaults when page fields are empty', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'is_indexable' => false,
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About Page — Acme')
        ->assertJsonPath('props.seo.description', 'Global description')
        ->assertJsonPath('props.seo.ogImage', Storage::disk('public')->url('media/global.jpg'))
        ->assertJsonPath('props.seo.canonical', url('/about'))
        ->assertJsonPath('props.seo.noindex', true);
});

it('falls back og:image to the page image when no og_image override is set', function (): void {
    $pageImage = media('page-body.jpg');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'image' => (string) $pageImage->getKey(),
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogImage', Storage::disk('public')->url('media/page-body.jpg'));
});

it('resolves og_image to null when the stored media id no longer exists', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'seo' => ['og_image' => '999999'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogImage', null);
});

it('falls back to the page summary for the meta description', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'summary' => ['en' => 'A short page summary.'],
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'A short page summary.');
});

it('falls back og:title/og:description to the resolved meta title/description when unset', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'summary' => ['en' => 'A short page summary.'],
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        // og:title falls back to the bare meta title (no title_suffix appended).
        ->assertJsonPath('props.seo.ogTitle', 'About Page')
        ->assertJsonPath('props.seo.ogDescription', 'A short page summary.');
});

it('lets explicit og_title/og_description overrides win over the meta fallback', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'seo' => [
            'title' => 'Meta title',
            'description' => 'Meta description',
            'og_title' => 'Custom OG title',
            'og_description' => 'Custom OG description',
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogTitle', 'Custom OG title')
        ->assertJsonPath('props.seo.ogDescription', 'Custom OG description');
});

it('ships ogTitle/ogDescription/ogImage props for the CmsPage <Head> to render as og: meta tags', function (): void {
    // No Inertia SSR is configured in this app (client-side <Head> only, see
    // CmsPage.tsx), so og: tags never land in server-rendered HTML — the wire
    // contract this test guards is the seo.og* props CmsPage consumes.
    $pageImage = media('page.jpg');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'seo' => [
            'og_title' => 'Custom OG title',
            'og_description' => 'Custom OG description',
            'og_image' => (string) $pageImage->getKey(),
        ],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogTitle', 'Custom OG title')
        ->assertJsonPath('props.seo.ogDescription', 'Custom OG description')
        ->assertJsonPath('props.seo.ogImage', Storage::disk('public')->url('media/page.jpg'));
});

it('falls back the meta title to the page name when title and seo.title are empty', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => ''],
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About — Acme')
        // og:title inherits the same cascade via metaTitle().
        ->assertJsonPath('props.seo.ogTitle', 'About');
});

it('uses the default-locale title fallback for a non-default-locale page', function (): void {
    Language::factory()->create(['locale' => 'uk']);

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'title' => ['en' => 'About Page'],
        'seo' => [],
    ]);

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.page.title', 'About Page')
        ->assertJsonPath('props.seo.title', 'About Page — Acme');
});
